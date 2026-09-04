<?php

use App\Domain\Requisition\Services\RequisitionService;
use App\Mail\AdvisorApprovalMail;
use App\Models\Requisition;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

function signedApprovalUrlFor(Requisition $requisition): string
{
    return URL::temporarySignedRoute(
        'requisitions.approve.signed',
        now()->addHours(72),
        ['requisition' => $requisition->ulid],
    );
}

test('submitting a STUDENT requisition emails the advisor a signed approval link (FR-RQ-07)', function () {
    Mail::fake();
    $student = studentUser();
    $requisition = makeRequisition($student);
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    app(\App\Domain\Requisition\Services\RequisitionService::class)->addLine($requisition, $item, $g, '5.000000');

    app(RequisitionService::class)->submit($requisition);

    Mail::assertQueued(AdvisorApprovalMail::class, fn ($mail) => $mail->hasTo($requisition->advisor->email)
        && $mail->requisition->id === $requisition->id);
});

test('submitting a STAFF (non-student) requisition does not email anyone', function () {
    Mail::fake();
    $staff = staffUser();
    $requisition = makeRequisition($staff);
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    app(RequisitionService::class)->addLine($requisition, $item, $g, '5.000000');

    app(RequisitionService::class)->submit($requisition);

    Mail::assertNothingSent();
});

test('a valid signed link shows the approval form', function () {
    $student = studentUser();
    $requisition = submittedRequisition($student);

    $this->get(signedApprovalUrlFor($requisition))
        ->assertOk()
        ->assertSee($requisition->doc_no);
});

test('a tampered or unsigned link is rejected', function () {
    $student = studentUser();
    $requisition = submittedRequisition($student);

    $this->get('/approve/'.$requisition->ulid)->assertStatus(403);
});

test('an expired signed link (past 72 hours) is rejected', function () {
    $student = studentUser();
    $requisition = submittedRequisition($student);
    $url = signedApprovalUrlFor($requisition);

    $this->travel(73)->hours();
    $this->get($url)->assertStatus(403);
});

test('approving via the signed link moves the requisition to ADVISOR_APPROVED', function () {
    $student = studentUser();
    $requisition = submittedRequisition($student);
    $url = signedApprovalUrlFor($requisition);

    $this->post($url, ['decision' => 'APPROVE'])->assertOk();

    expect($requisition->fresh()->status)->toBe('ADVISOR_APPROVED');
});

test('rejecting via the signed link without a reason is rejected', function () {
    $student = studentUser();
    $requisition = submittedRequisition($student);
    $url = signedApprovalUrlFor($requisition);

    $this->post($url, ['decision' => 'REJECT'])->assertSessionHasErrors('reason');
    expect($requisition->fresh()->status)->toBe('SUBMITTED');
});

test('the requisition\'s own advisor can decide in-system while logged in', function () {
    $student = studentUser();
    $requisition = submittedRequisition($student);
    $advisor = $requisition->advisor;

    $this->actingAs($advisor)->post(route('requisitions.advisor-decide', $requisition), [
        'decision' => 'APPROVE',
    ])->assertRedirect(route('requisitions.show', $requisition));

    expect($requisition->fresh()->status)->toBe('ADVISOR_APPROVED');
});

test('a different advisor cannot decide someone else\'s advisee requisition in-system', function () {
    $student = studentUser();
    $requisition = submittedRequisition($student);
    $otherAdvisor = User::factory()->create(['person_type' => 'LECTURER']);
    $otherAdvisor->roles()->attach(Role::where('code', 'ADVISOR')->firstOrFail());

    $this->actingAs($otherAdvisor)->post(route('requisitions.advisor-decide', $requisition), [
        'decision' => 'APPROVE',
    ])->assertStatus(403);
});
