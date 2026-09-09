<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('SEC-PD-02: an unconsented user is redirected to the Privacy Notice from any page', function () {
    $user = User::factory()->unconsented()->create();
    $user->roles()->attach(Role::where('code', 'SCIENTIST')->firstOrFail());

    $this->actingAs($user)->get('/')->assertRedirect(route('privacy-notice.show'));
    $this->actingAs($user)->get(route('items.index'))->assertRedirect(route('privacy-notice.show'));
});

test('SEC-PD-02: the Privacy Notice page itself is reachable without prior consent', function () {
    $user = User::factory()->unconsented()->create();

    $this->actingAs($user)->get(route('privacy-notice.show'))->assertOk();
});

test('SEC-PD-02: accepting the notice records consent and unblocks the rest of the app', function () {
    $user = User::factory()->unconsented()->create();
    $user->roles()->attach(Role::where('code', 'SCIENTIST')->firstOrFail());

    $response = $this->actingAs($user)->post(route('privacy-notice.accept'), ['agree' => '1']);

    $response->assertRedirect('/');
    expect($user->fresh()->privacy_consent_at)->not->toBeNull();
    expect($user->fresh()->privacy_consent_version)->toBe((string) config('privacy.notice_version'));
    expect(AuditLog::where('action', 'PRIVACY_CONSENT')->where('user_id', $user->id)->exists())->toBeTrue();

    $this->actingAs($user->fresh())->get(route('items.index'))->assertOk();
});

test('SEC-PD-02: accepting without checking the checkbox is rejected', function () {
    $user = User::factory()->unconsented()->create();

    $this->actingAs($user)->post(route('privacy-notice.accept'), [])
        ->assertSessionHasErrors('agree');
    expect($user->fresh()->privacy_consent_at)->toBeNull();
});

test('SEC-PD-02: a roleless user who just consented lands on pending-role, not a 403', function () {
    $user = User::factory()->unconsented()->create(); // no role attached

    $response = $this->actingAs($user)->post(route('privacy-notice.accept'), ['agree' => '1']);

    $response->assertRedirect(route('account.pending-role'));
});

test('SEC-PD-02: a stale notice version (bumped since last consent) is treated as unconsented', function () {
    $user = User::factory()->create(['privacy_consent_at' => now()->subYear(), 'privacy_consent_version' => '0.9']);
    $user->roles()->attach(Role::where('code', 'SCIENTIST')->firstOrFail());

    $this->actingAs($user)->get('/')->assertRedirect(route('privacy-notice.show'));
});
