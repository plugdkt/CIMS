<?php

use App\Domain\Loan\Services\LoanNotifier;
use App\Mail\NotificationMail;
use App\Models\LoanMovement;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
 * ITM-2610-051 step 1 (CEO, 2026-11-03): the person NAMED as approver of an ADJUST / RETIRE is told, in the
 * system and by email. Being named is not a confirmation (that comes with step 2) and the text says so. The
 * notice goes out only after the movement is recorded, to the approver only, once per submission; any failure
 * to notify is logged and never undoes the movement.
 */

const NOTIFY_NOTE = 'เหตุผลที่ยาวพอสำหรับทดสอบ';

if (! function_exists('loanMailsTo')) {
    function loanMailsTo(\App\Models\User $user): int
    {
        return Mail::sent(NotificationMail::class, fn ($m) => $m->hasTo($user->email))->count()
            + Mail::queued(NotificationMail::class, fn ($m) => $m->hasTo($user->email))->count();
    }
}

if (! function_exists('namedNotices')) {
    function namedNotices(): \Illuminate\Database\Eloquent\Collection
    {
        return Notification::where('type', 'loan.approver_named')->get();
    }
}

beforeEach(function () {
    Mail::fake();
    [$this->lab, $this->actor, $this->approver] = loanManagers();
    $this->bystander = auditorUser(['lab_id' => $this->lab->id]);   // same branch, but not named
    $this->asset = registerLoanAsset($this->actor, $this->lab, 'POOL', 10);
});

// --- one notice per successful ADJUST / RETIRE, to the approver only ------------------------------------------------

test('ADJUST_IN, ADJUST_OUT and RETIRE each notify the named approver once, in-app with a link to the asset, and by email', function (string $action) {
    $post = fn (array $extra) => $this->actingAs($this->actor)->post(route('loans.assets.'.($action === 'retire' ? 'retire' : 'adjust'), $this->asset), $extra + ['note' => NOTIFY_NOTE, 'approved_by' => $this->approver->id]);

    $response = match ($action) {
        'adjust_in' => $post(['direction' => 'IN', 'qty' => 2]),
        'adjust_out' => $post(['direction' => 'OUT', 'qty' => 2]),
        'retire' => $post([]),
    };
    $response->assertRedirect(route('loans.assets.show', $this->asset));

    $notices = namedNotices();
    expect($notices)->toHaveCount(1);
    expect($notices[0]->user_id)->toBe($this->approver->id);
    expect($notices[0]->link_url)->toBe(route('loans.assets.show', $this->asset));
    expect($notices[0]->title)->toBe(__('loans.notify.approver_named_title'));
    expect($notices[0]->body)->toContain(__('loans.notify.action_'.$action));

    expect(loanMailsTo($this->approver))->toBe(1);
    // Nobody else: not the person who did it, not another manager of the branch.
    expect(Notification::where('user_id', $this->actor->id)->count())->toBe(0);
    expect(Notification::where('user_id', $this->bystander->id)->count())->toBe(0);
    expect(loanMailsTo($this->actor))->toBe(0)->and(loanMailsTo($this->bystander))->toBe(0);
})->with(['adjust_in', 'adjust_out', 'retire']);

test('one submission sends one notice; two separate submissions send two', function () {
    $this->actingAs($this->actor)->post(route('loans.assets.adjust', $this->asset), ['direction' => 'IN', 'qty' => 1, 'note' => NOTIFY_NOTE, 'approved_by' => $this->approver->id]);
    expect(namedNotices())->toHaveCount(1);
    expect(loanMailsTo($this->approver))->toBe(1);

    $this->actingAs($this->actor)->post(route('loans.assets.adjust', $this->asset), ['direction' => 'IN', 'qty' => 1, 'note' => NOTIFY_NOTE, 'approved_by' => $this->approver->id]);
    expect(namedNotices())->toHaveCount(2);
});

test('approvals.md K: a SCIENTIST of the branch named as approver is notified (in-app and by email), and ADMIN can no longer be named', function () {
    $scientist = scientistUser(['lab_id' => $this->lab->id]);
    $admin = adminUser(['lab_id' => $this->lab->id]);

    $this->actingAs($this->actor)->post(route('loans.assets.adjust', $this->asset), ['direction' => 'IN', 'qty' => 1, 'note' => NOTIFY_NOTE, 'approved_by' => $scientist->id])
        ->assertRedirect(route('loans.assets.show', $this->asset));
    expect(namedNotices()->pluck('user_id')->all())->toBe([$scientist->id]);
    expect(loanMailsTo($scientist))->toBe(1);

    // Naming an ADMIN is refused: no movement, no notice, no mail.
    $this->actingAs($this->actor)->post(route('loans.assets.adjust', $this->asset), ['direction' => 'IN', 'qty' => 1, 'note' => NOTIFY_NOTE, 'approved_by' => $admin->id])
        ->assertSessionHasErrors();
    expect(namedNotices()->pluck('user_id')->all())->toBe([$scientist->id]);
    expect(loanMailsTo($admin))->toBe(0);
    expect(LoanMovement::where('loan_asset_id', $this->asset->id)->where('movement_type', 'ADJUST_IN')->count())->toBe(1);
});

test('an ADMIN may still be the person acting, naming a real branch approver', function () {
    $admin = adminUser();

    $this->actingAs($admin)->post(route('loans.assets.adjust', $this->asset), ['direction' => 'IN', 'qty' => 1, 'note' => NOTIFY_NOTE, 'approved_by' => $this->actor->id]);

    expect(namedNotices()->pluck('user_id')->all())->toBe([$this->actor->id]);
});

// --- nothing is sent when the movement is not recorded ---------------------------------------------------------------

test('a refused movement sends no notice at all', function (string $case) {
    $payload = ['direction' => 'IN', 'qty' => 1, 'note' => NOTIFY_NOTE, 'approved_by' => $this->approver->id];
    $route = 'loans.assets.adjust';

    switch ($case) {
        case 'approver is the actor':
            $payload['approved_by'] = $this->actor->id;
            break;
        case 'approver of another branch':
            $payload['approved_by'] = auditorUser(['lab_id' => makeLab()->id])->id;
            break;
        case 'note too short':
            $payload['note'] = 'สั้น';
            break;
        case 'adjust out too much':
            $payload['direction'] = 'OUT';
            $payload['qty'] = 10;   // would leave 0
            break;
        case 'retire while on loan':
            DB::table('loan_assets')->where('id', $this->asset->id)->update(['qty_on_loan' => 2]);
            $route = 'loans.assets.retire';
            unset($payload['direction'], $payload['qty']);
            break;
        case 'retire with the actor as approver':
            $route = 'loans.assets.retire';
            $payload = ['note' => NOTIFY_NOTE, 'approved_by' => $this->actor->id];
            break;
    }

    $this->actingAs($this->actor)->post(route($route, $this->asset), $payload)->assertSessionHasErrors();

    expect(Notification::count())->toBe(0);
    Mail::assertNothingQueued();
    Mail::assertNothingSent();
    expect(LoanMovement::where('loan_asset_id', $this->asset->id)->count())->toBe(1);   // only REGISTER
})->with(['approver is the actor', 'approver of another branch', 'note too short', 'adjust out too much', 'retire while on loan', 'retire with the actor as approver']);

test('a user of another branch is refused and nobody is notified', function () {
    $outsider = auditorUser(['lab_id' => makeLab()->id]);

    $this->actingAs($outsider)->post(route('loans.assets.adjust', $this->asset), ['direction' => 'IN', 'qty' => 1, 'note' => NOTIFY_NOTE, 'approved_by' => $this->approver->id])->assertForbidden();

    expect(Notification::count())->toBe(0);
});

// --- a failure to notify never undoes the movement ---------------------------------------------------------------------

test('if sending fails the movement is still recorded, the user is redirected as usual, and a warning is logged', function () {
    Log::spy();
    \App\Models\Notification::creating(fn () => throw new RuntimeException('mail server down'));

    $this->actingAs($this->actor)->post(route('loans.assets.adjust', $this->asset), ['direction' => 'IN', 'qty' => 3, 'note' => NOTIFY_NOTE, 'approved_by' => $this->approver->id])
        ->assertRedirect(route('loans.assets.show', $this->asset))
        ->assertSessionHasNoErrors();

    expect($this->asset->fresh()->qty_total)->toBe('13.000000');
    expect(LoanMovement::where('loan_asset_id', $this->asset->id)->where('movement_type', 'ADJUST_IN')->count())->toBe(1);
    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains((string) $message, 'Failed to notify the named loan approver'))->once();
});

test('a failure while retiring is survived the same way', function () {
    Log::spy();
    \App\Models\Notification::creating(fn () => throw new RuntimeException('mail server down'));

    $this->actingAs($this->actor)->post(route('loans.assets.retire', $this->asset), ['note' => NOTIFY_NOTE, 'approved_by' => $this->approver->id])
        ->assertRedirect(route('loans.assets.show', $this->asset));

    expect($this->asset->fresh()->status)->toBe('RETIRED');
});

// --- what the notice says --------------------------------------------------------------------------------------------

test('the text says being named is not a confirmation and whom to tell, and carries no personal data beyond the actor\'s display name', function () {
    $this->actor->forceFill(['username' => 'zz.secret.user', 'phone_encrypted' => '0899999999', 'person_code_encrypted' => '6500000001'])->save();

    $this->actingAs($this->actor->fresh())->post(route('loans.assets.adjust', $this->asset), ['direction' => 'IN', 'qty' => 1, 'note' => NOTIFY_NOTE, 'approved_by' => $this->approver->id]);

    $notice = namedNotices()->firstOrFail();
    $text = $notice->title.' '.$notice->body;
    expect($notice->body)->toContain('ไม่ใช่การยืนยัน')->toContain('กรุณาแจ้งผู้ดูแลระบบ');
    expect($notice->body)->toContain($this->actor->full_name)->toContain(NOTIFY_NOTE);
    foreach (['zz.secret.user', '0899999999', '6500000001', (string) $this->actor->email] as $leak) {
        expect($text)->not->toContain($leak);
    }

    // The email carries the same text.
    Mail::assertQueued(NotificationMail::class, fn ($mail) => $mail->hasTo($this->approver->email));
});

test('a long reason is cut, not dumped whole', function () {
    $long = str_repeat('ก', 450);

    $this->actingAs($this->actor)->post(route('loans.assets.adjust', $this->asset), ['direction' => 'IN', 'qty' => 1, 'note' => $long, 'approved_by' => $this->approver->id]);

    expect(mb_strlen(namedNotices()->firstOrFail()->body))->toBeLessThan(450);
});

test('the receiver can open the link: a manager of the branch gets 200', function () {
    $this->actingAs($this->actor)->post(route('loans.assets.adjust', $this->asset), ['direction' => 'IN', 'qty' => 1, 'note' => NOTIFY_NOTE, 'approved_by' => $this->approver->id]);

    foreach (namedNotices() as $notice) {
        $receiver = \App\Models\User::findOrFail($notice->user_id);
        $this->actingAs($receiver)->get($notice->link_url)->assertOk();
    }
    expect(namedNotices())->toHaveCount(1);
});

// --- LoanNotifier directly ---------------------------------------------------------------------------------------------

test('LoanNotifier sends nothing and throws nothing for an inactive, unknown, or self approver', function () {
    $notifier = app(LoanNotifier::class);
    $inactive = auditorUser(['lab_id' => $this->lab->id, 'is_active' => false]);

    foreach ([$inactive->id, 999999, $this->actor->id] as $approverId) {
        $notifier->approverNamed($this->actor, $this->asset, 'adjust_in', $approverId, NOTIFY_NOTE);
    }

    expect(Notification::count())->toBe(0);
    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

test('LoanNotifier sends to an active approver', function () {
    app(LoanNotifier::class)->approverNamed($this->actor, $this->asset, 'retire', $this->approver->id, NOTIFY_NOTE);

    expect(namedNotices()->pluck('user_id')->all())->toBe([$this->approver->id]);
});
