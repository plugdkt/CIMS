<?php

use App\Domain\Notification\Services\NotificationService;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
 * ITM-2610-052 phase 3: "a requisition is waiting for a warehouse decision" goes to the active
 * managers of the requisition's OWN branch who are responsible for its kind(s) (every kind, for a
 * legacy mixed one) — not to every approver of every branch as before. If nobody qualifies, every
 * ADMIN is told to assign someone (requisition.no_approver); ADMIN cannot decide requisitions.
 */

beforeEach(function () {
    Mail::fake();
    $this->labA = makeLab();
    $this->labB = makeLab();

    $this->chemA = labManagerUser(['lab_id' => $this->labA->id, 'itemTypes' => ['chemical']]);
    $this->supplyA = auditorUser(['lab_id' => $this->labA->id, 'itemTypes' => ['consumable']]);
    $this->bothA = auditorUser(['lab_id' => $this->labA->id]);
    $this->noneA = labManagerUser(['lab_id' => $this->labA->id, 'itemTypes' => []]);
    $this->inactiveChemA = labManagerUser(['lab_id' => $this->labA->id, 'itemTypes' => ['chemical'], 'is_active' => false]);
    $this->chemB = labManagerUser(['lab_id' => $this->labB->id, 'itemTypes' => ['chemical']]);
    $this->bothB = auditorUser(['lab_id' => $this->labB->id]);

    $this->notified = fn (string $type) => Notification::where('type', $type)->pluck('user_id')->sort()->values()->all();
    $this->ids = fn (array $users) => collect($users)->map->id->sort()->values()->all();
    $this->requisition = fn (array $categories, $lab = null) => requisitionWithLines(
        staffUser(['lab_id' => ($lab ?? $this->labA)->id]),
        $categories,
        ['lab_id' => ($lab ?? $this->labA)->id, 'status' => 'SUBMITTED'],
    );
});

test('a chemical requisition of branch A reaches only the active chemical managers of branch A', function () {
    app(NotificationService::class)->notifyApprovalPending(($this->requisition)(['CHEMICAL']));

    // chemA and bothA only: not the consumable manager, the manager with no kinds, the deactivated one, or any branch B manager.
    expect(($this->notified)('requisition.pending_scientist'))->toBe(($this->ids)([$this->chemA, $this->bothA]));
    expect(($this->notified)('requisition.no_approver'))->toBe([]);
});

test('a consumable requisition reaches the consumable managers of its branch only', function () {
    app(NotificationService::class)->notifyApprovalPending(($this->requisition)(['MATERIAL']));

    expect(($this->notified)('requisition.pending_scientist'))->toBe(($this->ids)([$this->supplyA, $this->bothA]));
});

test('a legacy mixed requisition reaches only managers who hold both kinds', function () {
    app(NotificationService::class)->notifyApprovalPending(($this->requisition)(['CHEMICAL', 'GLASSWARE']));

    expect(($this->notified)('requisition.pending_scientist'))->toBe(($this->ids)([$this->bothA]));
});

test('the same requisition in branch B reaches branch B managers, never branch A', function () {
    app(NotificationService::class)->notifyApprovalPending(($this->requisition)(['CHEMICAL'], $this->labB));

    expect(($this->notified)('requisition.pending_scientist'))->toBe(($this->ids)([$this->chemB, $this->bothB]));
});

test('approversFor lists the same people the notification goes to', function () {
    $requisition = ($this->requisition)(['CHEMICAL']);

    expect(app(NotificationService::class)->approversFor($requisition)->pluck('id')->sort()->values()->all())
        ->toBe(($this->ids)([$this->chemA, $this->bothA]));
});

test('nobody responsible for the kind in that branch: every ADMIN is told, nobody else, and ADMIN still cannot decide', function () {
    $labC = makeLab();   // a branch with no manager at all
    $adminOne = adminUser();
    $adminTwo = adminUser(['lab_id' => $labC->id]);
    $requisition = ($this->requisition)(['CHEMICAL'], $labC);

    // Managers of other branches are not told either.
    app(NotificationService::class)->notifyApprovalPending($requisition);

    expect(($this->notified)('requisition.pending_scientist'))->toBe([]);
    expect(($this->notified)('requisition.no_approver'))->toBe(($this->ids)([$adminOne, $adminTwo]));

    $this->actingAs($adminOne)->post(route('requisitions.scientist-decide', $requisition), ['decision' => 'APPROVE'])->assertForbidden();
    expect($requisition->fresh()->status)->toBe('SUBMITTED');
});

test('a branch whose managers hold only the other kind also falls back to ADMIN', function () {
    $admin = adminUser();
    // Branch A has chemical and consumable managers, so use a branch where only a consumable manager exists.
    $labC = makeLab();
    $supplyOnly = auditorUser(['lab_id' => $labC->id, 'itemTypes' => ['consumable']]);

    app(NotificationService::class)->notifyApprovalPending(($this->requisition)(['CHEMICAL'], $labC));

    expect(($this->notified)('requisition.pending_scientist'))->toBe([]);
    expect(($this->notified)('requisition.no_approver'))->toBe(($this->ids)([$admin]));
    expect(Notification::where('user_id', $supplyOnly->id)->count())->toBe(0);
});

test('submitting a STAFF requisition triggers the per-kind notification (not the old broadcast)', function () {
    $staff = staffUser(['lab_id' => $this->labA->id]);
    $requisition = requisitionWithLines($staff, ['CHEMICAL'], ['lab_id' => $this->labA->id, 'status' => 'DRAFT']);

    $this->actingAs($staff)->post(route('requisitions.submit', $requisition))->assertSessionHasNoErrors();

    expect(($this->notified)('requisition.pending_scientist'))->toBe(($this->ids)([$this->chemA, $this->bothA]));
});

test('the pending notice tells the approver it awaits "นักวิทยาศาสตร์หรือผู้ดูแลคลัง" consideration, naming the document', function () {
    $requisition = ($this->requisition)(['CHEMICAL']);

    app(NotificationService::class)->notifyApprovalPending($requisition);

    $notification = Notification::where('type', 'requisition.pending_scientist')->where('user_id', $this->chemA->id)->firstOrFail();
    expect($notification->body)->toContain($requisition->doc_no)
        ->toBe((string) __('notifications.pending_scientist_body', ['doc_no' => $requisition->doc_no]))
        ->toEndWith('รอการพิจารณาจากนักวิทยาศาสตร์หรือผู้ดูแลคลังของสาขา');
});
