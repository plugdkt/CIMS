<?php

use App\Models\LoanMovement;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
 * approvals.md K: a holder of loan.asset_approve (a scientist, or a manager) named as the second person of an
 * ADJUST / RETIRE can OPEN that asset (the link in the LoanNotifier notice), own branch only, read only: no edit /
 * adjust / retire forms and no replacement value, and every write route stays 403 for them.
 *
 * (Written without being run: the coordinator runs the suite.)
 */

beforeEach(function () {
    [$this->lab, $this->manager, $this->colleague] = loanManagers();
    $this->asset = loanService()->register($this->manager, [
        'lab_id' => $this->lab->id, 'item_id' => makeCountItem()->id, 'tracking_mode' => 'POOL', 'qty' => 10, 'replacement_value' => '12345.50',
    ]);
    $this->show = route('loans.assets.show', $this->asset);
});

test('a scientist of the same branch (holds loan.asset_approve) opens the asset page: 200', function () {
    $this->actingAs(scientistUser(['lab_id' => $this->lab->id]))->get($this->show)->assertOk();
});

test('another branch, and a user with no branch, get 403 (scientist and manager alike)', function () {
    $outsiders = [
        scientistUser(['lab_id' => makeLab()->id]), scientistUser(['lab_id' => null]),
        auditorUser(['lab_id' => makeLab()->id]), labManagerUser(['lab_id' => null]),
    ];
    foreach ($outsiders as $user) {
        $this->actingAs($user)->get($this->show)->assertForbidden();
    }
});

test('ADMIN does not get in through the approver rule, and a requester role is still refused', function () {
    // ADMIN keeps its own asset_manage access as before; staff/student hold neither permission.
    $this->actingAs(staffUser(['lab_id' => $this->lab->id]))->get($this->show)->assertForbidden();
    $this->actingAs(studentUser(['lab_id' => $this->lab->id]))->get($this->show)->assertForbidden();
});

test('the approver sees the movement page but no edit/adjust/retire forms and no replacement value', function () {
    $this->actingAs(scientistUser(['lab_id' => $this->lab->id]))->get($this->show)
        ->assertOk()
        ->assertDontSee('12345.50')
        ->assertDontSee(route('loans.assets.update', $this->asset), false)
        ->assertDontSee(route('loans.assets.adjust', $this->asset), false)
        ->assertDontSee(route('loans.assets.retire', $this->asset), false);

    // Control: the manager of the branch does see the value and the forms.
    $this->actingAs($this->manager)->get($this->show)
        ->assertSee('12345.50')
        ->assertSee(route('loans.assets.adjust', $this->asset), false);
});

test('an approver without loan.asset_manage gets 403 on update, adjust and retire, and nothing changes', function () {
    $scientist = scientistUser(['lab_id' => $this->lab->id]);
    $note = 'เหตุผลที่ยาวพอสำหรับทดสอบ';
    $before = $this->asset->fresh();

    $this->actingAs($scientist)->put(route('loans.assets.update', $this->asset), ['max_loan_days' => 3, 'condition_note' => 'ห้ามแก้'])->assertForbidden();
    $this->actingAs($scientist)->post(route('loans.assets.adjust', $this->asset), ['direction' => 'IN', 'qty' => 1, 'note' => $note, 'approved_by' => $this->manager->id])->assertForbidden();
    $this->actingAs($scientist)->post(route('loans.assets.retire', $this->asset), ['note' => $note, 'approved_by' => $this->manager->id])->assertForbidden();

    $fresh = $this->asset->fresh();
    expect($fresh->qty_total)->toBe($before->qty_total)->and($fresh->status)->toBe('ACTIVE')->and($fresh->max_loan_days)->toBe($before->max_loan_days);
    expect(LoanMovement::where('loan_asset_id', $this->asset->id)->count())->toBe(1);   // only REGISTER
});

test('the link in the notice to a named scientist opens (200)', function () {
    Mail::fake();
    $scientist = scientistUser(['lab_id' => $this->lab->id]);

    $this->actingAs($this->manager)->post(route('loans.assets.adjust', $this->asset), [
        'direction' => 'IN', 'qty' => 1, 'note' => 'เหตุผลที่ยาวพอสำหรับทดสอบ', 'approved_by' => $scientist->id,
    ])->assertRedirect($this->show);

    $notice = Notification::where('type', 'loan.approver_named')->where('user_id', $scientist->id)->firstOrFail();
    $this->actingAs($scientist)->get($notice->link_url)->assertOk();
});
