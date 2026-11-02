<?php

use App\Domain\Requisition\Exceptions\InvalidApprovalDecisionException;
use App\Domain\Requisition\Services\ApprovalService;
use App\Models\RequisitionApproval;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

/*
 * ITM-2610-054 (BR-02 hardening): being named as the advisor on a requisition is not enough to
 * approve it. The advisor must still be active, still hold requisition.approve_advisor, and must
 * not be the requester, through both channels (signed email link, and logged in). One generic
 * message for every refusal, so nothing about the account or the requisition is revealed.
 */

if (! function_exists('advisorGateSignedUrl')) {
    function advisorGateSignedUrl(\App\Models\Requisition $requisition): string
    {
        return URL::temporarySignedRoute('requisitions.approve.signed', now()->addHours(72), ['requisition' => $requisition->ulid]);
    }
}

if (! function_exists('assertAdvisorRefused')) {
    /** Nothing about the requisition changed and nothing was recorded. */
    function assertAdvisorRefused(\App\Models\Requisition $requisition): void
    {
        $fresh = $requisition->fresh();
        expect($fresh->status)->toBe('SUBMITTED');
        expect($fresh->advisor_signed_at)->toBeNull();
        expect($fresh->advisor_signature_hash)->toBeNull();
        expect(RequisitionApproval::where('requisition_id', $requisition->id)->count())->toBe(0);
    }
}

if (! function_exists('breakAdvisor')) {
    /** Makes the named advisor of a requisition fail the gate in one specific way. */
    function breakAdvisor(string $cause, \App\Models\Requisition $requisition, User $student): void
    {
        match ($cause) {
            'deactivated' => $requisition->advisor->update(['is_active' => false]),
            'revoked' => $requisition->advisor->roles()->detach(Role::where('code', 'ADVISOR')->firstOrFail()),
            // Even holding the advisor permission, nobody approves their own requisition.
            'self' => (function () use ($requisition, $student) {
                $student->roles()->attach(Role::where('code', 'ADVISOR')->firstOrFail());
                $requisition->update(['advisor_id' => $student->id]);
            })(),
        };
    }
}

dataset('ineligible advisors', ['deactivated', 'revoked', 'self']);

test('signed link: an ineligible advisor cannot approve or reject; the requisition is untouched and the message does not say why', function (string $cause) {
    $student = studentUser();
    $requisition = submittedRequisition($student);
    breakAdvisor($cause, $requisition, $student);
    $url = advisorGateSignedUrl($requisition->fresh());

    foreach ([['decision' => 'APPROVE'], ['decision' => 'REJECT', 'reason' => 'ไม่เหมาะสม']] as $payload) {
        $response = $this->post($url, $payload)->assertOk();

        expect($response->viewData('failed'))->toBeTrue();
        expect($response->viewData('status'))->toBe(__('requisitions.advisor_not_eligible'));
        assertAdvisorRefused($requisition);
    }
})->with('ineligible advisors');

test('the refusal text is identical for every cause and names none of them', function () {
    $message = __('requisitions.advisor_not_eligible');

    foreach (['is_active', 'inactive', 'deactivated', 'revoked', 'permission', 'requester', 'ปิดใช้งาน', 'ถอดสิทธิ์', 'ตัวเอง', 'ผู้ขอ'] as $leak) {
        expect($message)->not->toContain($leak);
    }

    $messages = [];
    foreach (['deactivated', 'revoked', 'self'] as $cause) {
        $student = studentUser();
        $requisition = submittedRequisition($student);
        match ($cause) {
            'deactivated' => $requisition->advisor->update(['is_active' => false]),
            'revoked' => $requisition->advisor->roles()->detach(Role::where('code', 'ADVISOR')->firstOrFail()),
            'self' => (function () use ($student, $requisition) {
                $student->roles()->attach(Role::where('code', 'ADVISOR')->firstOrFail());
                $requisition->update(['advisor_id' => $student->id]);
            })(),
        };
        $messages[$cause] = $this->post(advisorGateSignedUrl($requisition->fresh()), ['decision' => 'APPROVE'])->viewData('status');
    }

    expect(array_unique($messages))->toBe(['deactivated' => $message]);
});

test('in-system channel: an ineligible advisor cannot decide either, and the requisition is untouched', function (string $cause) {
    $student = studentUser();
    $requisition = submittedRequisition($student);
    breakAdvisor($cause, $requisition, $student);
    $requisition = $requisition->fresh();
    $actor = User::findOrFail($requisition->advisor_id);

    // Refused either by the policy (403) or by the service (back with an error): both leave nothing behind.
    $this->actingAs($actor)->post(route('requisitions.advisor-decide', $requisition), ['decision' => 'APPROVE']);

    assertAdvisorRefused($requisition);
})->with('ineligible advisors');

test('in-system channel: a requester who is also their own named advisor is refused with the generic message', function () {
    $student = studentUser();
    $student->roles()->attach(Role::where('code', 'ADVISOR')->firstOrFail());
    $requisition = submittedRequisition($student);
    $requisition->update(['advisor_id' => $student->id]);

    $this->actingAs($student->fresh())->post(route('requisitions.advisor-decide', $requisition), ['decision' => 'APPROVE'])
        ->assertSessionHasErrors(['decision' => __('requisitions.advisor_not_eligible')]);

    assertAdvisorRefused($requisition);
});

test('service level: every ineligible advisor throws the generic message and writes nothing', function (string $cause) {
    $student = studentUser();
    $requisition = submittedRequisition($student);
    breakAdvisor($cause, $requisition, $student);
    $requisition = $requisition->fresh();
    $advisor = User::findOrFail($requisition->advisor_id);

    expect(fn () => app(ApprovalService::class)->advisorDecide($requisition, $advisor, 'APPROVE'))
        ->toThrow(InvalidApprovalDecisionException::class, __('requisitions.advisor_not_eligible'));

    assertAdvisorRefused($requisition);
})->with('ineligible advisors');

test('a real advisor (active, holds the permission, not the requester) still approves by both channels', function () {
    $student = studentUser();
    $viaLink = submittedRequisition($student);
    $viaSystem = submittedRequisition($student);

    $this->post(advisorGateSignedUrl($viaLink), ['decision' => 'APPROVE'])->assertOk();
    expect($viaLink->fresh()->status)->toBe('ADVISOR_APPROVED');
    expect($viaLink->fresh()->advisor_signed_at)->not->toBeNull();
    expect(RequisitionApproval::where('requisition_id', $viaLink->id)->where('step', 'ADVISOR')->count())->toBe(1);

    $this->actingAs($viaSystem->advisor)->post(route('requisitions.advisor-decide', $viaSystem), ['decision' => 'APPROVE'])
        ->assertRedirect(route('requisitions.show', $viaSystem));
    expect($viaSystem->fresh()->status)->toBe('ADVISOR_APPROVED');
});

test('a real advisor can still reject with a reason, and without one is still asked for it', function () {
    $student = studentUser();
    $requisition = submittedRequisition($student);

    $this->post(advisorGateSignedUrl($requisition), ['decision' => 'REJECT'])->assertSessionHasErrors('reason');
    expect($requisition->fresh()->status)->toBe('SUBMITTED');

    $this->post(advisorGateSignedUrl($requisition), ['decision' => 'REJECT', 'reason' => 'ไม่เหมาะสม'])->assertOk();
    expect($requisition->fresh()->status)->toBe('REJECTED');
});

test('BR-02 is unchanged: the scientist still cannot skip the advisor on a STUDENT requisition', function () {
    $student = studentUser();
    $requisition = submittedRequisition($student);

    expect(fn () => app(ApprovalService::class)->scientistDecide($requisition, scientistUser(), 'APPROVE'))
        ->toThrow(InvalidApprovalDecisionException::class, 'BR-02');

    expect($requisition->fresh()->status)->toBe('SUBMITTED');
});

test('attack: a student who makes themself their own advisor cannot approve their own requisition, via the form or via data set directly', function () {
    $lab = makeLab();
    $student = studentUser(['lab_id' => $lab->id, 'profile_completed_at' => null, 'person_type' => null, 'advisor_id' => null]);
    $student->roles()->attach(Role::where('code', 'ADVISOR')->firstOrFail());   // even if they also hold the advisor permission

    // Through the form: refused, nothing stored.
    $this->actingAs($student)->post(route('account.complete-profile.update'), [
        'person_type' => 'STUDENT', 'phone' => '0812345678', 'person_code' => '65123456', 'lab_id' => $lab->id,
        'program' => 'เคมี', 'faculty' => 'วิทยาศาสตร์', 'advisor_id' => $student->id,
    ])->assertSessionHasErrors('advisor_id');
    expect($student->fresh()->advisor_id)->toBeNull();

    // Even if the value is forced straight into the database, the approval gate still stops it.
    $student->forceFill(['advisor_id' => $student->id, 'person_type' => 'STUDENT', 'profile_completed_at' => now()])->save();
    $requisition = submittedRequisition($student->fresh(), ['advisor_id' => $student->id]);

    $this->post(advisorGateSignedUrl($requisition), ['decision' => 'APPROVE'])
        ->assertOk()->assertSee(__('requisitions.advisor_not_eligible'));
    $this->actingAs($student->fresh())->post(route('requisitions.advisor-decide', $requisition), ['decision' => 'APPROVE']);

    assertAdvisorRefused($requisition);
});
