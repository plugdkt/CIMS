<?php

use App\Models\Lab;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function fakeSsoVerifySuccess(array $overrides = []): void
{
    Http::fake([
        config('services.sso.verify_url') => Http::response([
            'status' => 'success',
            'user' => array_merge([
                'user_id' => 4242,
                'username' => 'somchai.j',
                'name' => 'สมชาย ใจดี',
                'pos_name' => 'อาจารย์',
                'div_name' => 'ภาควิชาเคมี',
                'email' => 'somchai.j@up.ac.th',
            ], $overrides),
        ], 200),
    ]);
}

test('GET /login redirects to the SSO login URL with client_id, redirect_uri, and a state stored in session', function () {
    $response = $this->get('/login');

    $response->assertRedirect();
    $location = $response->headers->get('Location');

    expect($location)->toStartWith(config('services.sso.login_url'));
    expect($location)->toContain('client_id='.config('services.sso.client_id'));
    expect(session('sso_state'))->not->toBeNull();
});

test('callback rejects a state that does not match the session without calling the verify API', function () {
    $this->withSession(['sso_state' => 'correct-state']);
    fakeSsoVerifySuccess();

    $response = $this->get('/sso/callback?token=sometoken&state=WRONG-state');

    $response->assertStatus(400);
    Http::assertNothingSent();
    expect(session('sso_state'))->toBeNull(); // one-time use, consumed either way
});

test('callback creates a new user with no role and sends them to the Privacy Notice first (SEC-PD-02, before pending-role)', function () {
    $this->withSession(['sso_state' => 'good-state']);
    fakeSsoVerifySuccess(['user_id' => 9999, 'username' => 'newperson']);

    $response = $this->get('/sso/callback?token=validtoken&state=good-state');

    $response->assertRedirect(route('privacy-notice.show'));
    $this->assertAuthenticated();

    $user = User::where('sso_subject', '9999')->first();
    expect($user)->not->toBeNull();
    expect($user->roles)->toBeEmpty();
    expect($user->privacy_consent_at)->toBeNull();
});

test('callback sends an already-consented but roleless user to pending-role, past the Privacy Notice', function () {
    $this->withSession(['sso_state' => 'good-state']);
    fakeSsoVerifySuccess(['user_id' => 8888, 'username' => 'alreadyconsented']);
    User::factory()->create(['sso_subject' => '8888']); // consented by default (factory)

    $response = $this->get('/sso/callback?token=validtoken&state=good-state');

    $response->assertRedirect(route('account.pending-role'));
});

test('callback logs in an existing user with a role and redirects past the gate', function () {
    $role = Role::where('code', 'SCIENTIST')->firstOrFail();
    $existing = User::factory()->create(['sso_subject' => '5555', 'username' => 'wipawan']);
    $existing->roles()->attach($role);

    $this->withSession(['sso_state' => 'good-state']);
    fakeSsoVerifySuccess(['user_id' => 5555, 'username' => 'wipawan']);

    $response = $this->get('/sso/callback?token=validtoken&state=good-state');

    $response->assertRedirect('/');
    $this->assertAuthenticatedAs($existing->fresh());
});

test('a token cannot be replayed: the second callback with the same state fails', function () {
    $this->withSession(['sso_state' => 'one-time-state']);
    fakeSsoVerifySuccess(['user_id' => 7777]);

    $this->get('/sso/callback?token=tok&state=one-time-state')->assertRedirect();

    // state was consumed by the first request — session no longer has it.
    $second = $this->get('/sso/callback?token=tok&state=one-time-state');
    $second->assertStatus(400);
});

test('an authenticated user with no role gets HTTP 403 on any route except pending-role/logout', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')->assertStatus(403);
    $this->actingAs($user)->get(route('account.pending-role'))->assertOk();
});

test('a pre-created account with sso_subject still null is claimed by username, not duplicated, on its first real login', function () {
    $lab = Lab::create(['code' => 'LAB-PHYSIO', 'name_th' => 'สรีรวิทยา', 'is_active' => true]);
    $preCreated = User::create([
        'ulid' => (string) \Illuminate\Support\Str::ulid(),
        'sso_subject' => null,
        'username' => 'newperson',
        'email' => 'newperson@up.ac.th',
        'full_name' => 'บุคคล ใหม่',
        'pos_name' => 'นักวิทยาศาสตร์',
        'div_name' => 'สรีรวิทยา',
        'lab_id' => $lab->id,
        'is_active' => true,
    ]);

    $this->withSession(['sso_state' => 'good-state']);
    fakeSsoVerifySuccess([
        'user_id' => 6161, 'username' => 'newperson', 'name' => 'สมชาย ใจดี (จริง)',
        'pos_name' => 'อาจารย์', 'div_name' => 'ภาควิชาสรีรวิทยา', 'email' => 'newperson@up.ac.th',
    ]);

    $this->get('/sso/callback?token=validtoken&state=good-state');

    // Same row (same id), not a second account for the same person.
    expect(User::where('username', 'newperson')->count())->toBe(1);
    $user = $preCreated->fresh();
    expect($user->sso_subject)->toBe('6161');
    expect($user->lab_id)->toBe($lab->id); // the pre-assigned branch survives the real login
    expect($user->full_name)->toBe('สมชาย ใจดี (จริง)'); // SSO payload overwrote the placeholder
    expect($user->pos_name)->toBe('อาจารย์');
});

test('a username with no pre-created account still gets a genuinely new one on first login, as before', function () {
    $this->withSession(['sso_state' => 'good-state']);
    fakeSsoVerifySuccess(['user_id' => 6363, 'username' => 'trulynew']);

    $this->get('/sso/callback?token=validtoken&state=good-state');

    $user = User::where('sso_subject', '6363')->firstOrFail();
    expect($user->lab_id)->toBeNull();
});

test('when SSO verify returns empty or missing email, it falls back to username@up.ac.th', function () {
    $this->withSession(['sso_state' => 'good-state']);
    fakeSsoVerifySuccess(['user_id' => 7171, 'username' => 'surachet.ku', 'email' => '']);

    $this->get('/sso/callback?token=validtoken&state=good-state');

    $user = User::where('sso_subject', '7171')->firstOrFail();
    expect($user->email)->toBe('surachet.ku@up.ac.th');
});

test('multiple users with empty SSO email can log in without unique constraint violation', function () {
    // Fixed 2026-09-21: calling fakeSsoVerifySuccess() twice for the same URL doesn't work —
    // Http::fake() matches the FIRST registered stub for a matching URL pattern
    // (PendingRequest::buildStubHandler() resolves via ->filter()->first()), so a second
    // fakeSsoVerifySuccess() call is silently ignored and both requests would return the
    // first payload. Http::fakeSequence() returns responses in call order instead, which is
    // what this test actually needs (two distinct users provisioned in the same test run).
    Http::fakeSequence(config('services.sso.verify_url'))
        ->push(['status' => 'success', 'user' => [
            'user_id' => 7172, 'username' => 'user.one', 'name' => 'สมชาย ใจดี',
            'pos_name' => 'อาจารย์', 'div_name' => 'ภาควิชาเคมี', 'email' => '',
        ]])
        ->push(['status' => 'success', 'user' => [
            'user_id' => 7173, 'username' => 'user.two', 'name' => 'สมชาย ใจดี',
            'pos_name' => 'อาจารย์', 'div_name' => 'ภาควิชาเคมี', 'email' => '',
        ]]);

    $this->withSession(['sso_state' => 'state-1']);
    $this->get('/sso/callback?token=validtoken&state=state-1');

    $this->withSession(['sso_state' => 'state-2']);
    $this->get('/sso/callback?token=validtoken&state=state-2');

    expect(User::where('username', 'user.one')->firstOrFail()->email)->toBe('user.one@up.ac.th');
    expect(User::where('username', 'user.two')->firstOrFail()->email)->toBe('user.two@up.ac.th');
});

test('an existing user with an email keeps it if SSO returns an empty email', function () {
    $user = User::factory()->create([
        'sso_subject' => '7174',
        'username' => 'existing.user',
        'email' => 'existing.user@up.ac.th',
    ]);

    $this->withSession(['sso_state' => 'good-state']);
    fakeSsoVerifySuccess(['user_id' => 7174, 'username' => 'existing.user', 'email' => '']);
    $this->get('/sso/callback?token=validtoken&state=good-state');

    expect($user->fresh()->email)->toBe('existing.user@up.ac.th');
});
