<?php

use App\Domain\Loan\Exceptions\LoanDomainException;
use App\Domain\Loan\Services\LoanCirculationService;
use App\Domain\Loan\Services\LoanIncidentService;
use App\Domain\Loan\Services\LoanNotifier;
use App\Domain\Loan\Services\LoanService;
use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\LoanAsset;
use App\Models\LoanIncident;
use App\Models\LoanMovement;
use App\Models\Notification;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
 * ITM-2610-051 step 4 (WP-4.6): damage / loss incidents and their results — what is WRITTEN.
 *
 *  - A return may take pieces back DAMAGED or write them down as LOST: each becomes an incident (PENDING), a
 *    RETURN_DAMAGED / MARK_LOST movement, and the pieces move from "on loan" to "unavailable" (not lendable).
 *  - The result (REPAIRED / COMPENSATED / WAIVED / REPLACED) puts the pieces back into service (REPAIR_DONE) or
 *    writes them off (WRITE_OFF, + REPLACE_IN by a second person for REPLACED), in one transaction.
 *  - BR-L12: the loan is not closed while an incident is PENDING and closes with the last result.
 *  - A recorded result is final: the service, the model and the trigger `trg_loan_incidents_final` all refuse.
 *  - `loans:verify` passes after every step and catches a hand-edited counter / incident.
 *
 * Every refusal is checked to write NOTHING (loanIncidentState() before / after).
 * WHO may record / see / attach, the files, the notices and what the borrower sees: LoanIncidentAccessTest.
 * Tests named [CONFIRM] pin a rule that follows the code and still waits for the CEO; only they flip if it changes.
 */

beforeEach(function () {
    Mail::fake();
});

const INCIDENT_NOTE = 'ขาตั้งหักระหว่างใช้งานในห้องปฏิบัติการ';   // >= 10 characters
const WEAR_NOTE = 'สึกหรอจากการใช้งานตามปกติ';   // >= 10 characters (BR-L09), for a usable return in a worse condition

/**
 * @return array{\App\Models\Lab, \App\Models\LoanAsset, \App\Models\User, \App\Models\User, \App\Models\User, \App\Models\User}
 *                                                                                                                   lab, POOL asset, borrower (STAFF), recorder (SCIENTIST at the counter), resolver (AUDITOR = warehouse manager), receiver (another SCIENTIST)
 */
function incidentScene(int $qty = 10): array
{
    [$lab, $asset] = loanBranch($qty);

    return [
        $lab, $asset,
        staffUser(['lab_id' => $lab->id]),
        scientistUser(['lab_id' => $lab->id]),
        auditorUser(['lab_id' => $lab->id]),
        scientistUser(['lab_id' => $lab->id]),
    ];
}

function setLoanAssetValue(LoanAsset $asset, ?string $value): void
{
    DB::table('loan_assets')->where('id', $asset->id)->update(['replacement_value' => $value]);
}

/** The movement types written for an incident, in order. @return list<string> */
function incidentMovementTypes(LoanIncident $incident): array
{
    return LoanMovement::where('incident_id', $incident->id)->orderBy('id')->pluck('movement_type')->all();
}

// --- recording an incident on the return page ----------------------------------------------------------------------

test('a piece that comes back DAMAGED becomes a PENDING incident: RETURN_DAMAGED, on-loan -> unavailable, not lendable, the loan stays open, loans:verify passes', function () {
    [$lab, $asset, $borrower, $recorder] = incidentScene(10);
    $loan = handedOutLoan($borrower, $asset, 3);
    $line = $loan->lines()->firstOrFail();
    expect($asset->fresh()->available())->toBe('7.000000');

    // The row exactly as the return page sends it (the other boxes left empty).
    loanReturnPost($this, $recorder, $loan, [$line->line_no => [
        'qty' => '', 'condition' => 'GOOD', 'note' => '', 'qty_damaged' => '1', 'qty_lost' => '', 'incident_note' => '  '.INCIDENT_NOTE.'  ',
    ]])->assertRedirect(route('loans.show', $loan))->assertSessionHas('status', __('loans.returned_incident'));

    $incidents = LoanIncident::where('loan_id', $loan->id)->get();
    expect($incidents)->toHaveCount(1);
    $incident = $incidents[0];
    expect([$incident->kind, $incident->qty, $incident->resolution, $incident->disposition, $incident->description])
        ->toBe(['DAMAGED', '1.000000', 'PENDING', null, INCIDENT_NOTE]);
    expect([$incident->loan_line_id, $incident->recorded_by, $incident->resolved_by, $incident->resolved_at, $incident->value_snapshot, $incident->external_ref])
        ->toBe([$line->id, $recorder->id, null, null, null, null]);
    expect($incident->ulid)->toHaveLength(26)->and($incident->isPending())->toBeTrue();

    // The document: one piece is no longer with the borrower, two are; not closed.
    $line = $line->fresh();
    expect([$line->qty_returned_ok, $line->qty_returned_damaged, $line->qty_lost, $line->outstanding()])->toBe(['0.000000', '1.000000', '0.000000', '2.000000']);
    $fresh = $loan->fresh();
    expect($fresh->status)->toBe('PARTIALLY_RETURNED')->and($fresh->closed_at)->toBeNull();

    // The register: the piece left "on loan" but did NOT become available.
    $a = $asset->fresh();
    expect([$a->qty_total, $a->qty_on_loan, $a->qty_unavailable, $a->available(), $a->status])->toBe(['10.000000', '2.000000', '1.000000', '7.000000', 'ACTIVE']);

    // The ledger: exactly one new row, tied to the incident.
    $rows = LoanMovement::where('incident_id', $incident->id)->get();
    expect($rows)->toHaveCount(1);
    $row = $rows[0];
    expect([$row->movement_type, $row->qty, $row->condition_recorded, $row->loan_id, $row->loan_line_id, $row->loan_asset_id])
        ->toBe(['RETURN_DAMAGED', '1.000000', 'DAMAGED', $loan->id, $line->id, $asset->id]);
    expect([$row->qty_total_after, $row->qty_on_loan_after, $row->qty_unavailable_after])->toBe(['10.000000', '2.000000', '1.000000']);
    expect([$row->created_by, $row->approved_by, $row->external_ref, $row->note])->toBe([$recorder->id, null, null, INCIDENT_NOTE]);
    expect(LoanMovement::where('loan_id', $loan->id)->orderBy('id')->pluck('movement_type')->all())->toBe(['HANDOUT', 'RETURN_DAMAGED']);

    // Audit: the incident and the return, without the free text.
    $recorded = loanIncidentAudit('LOAN_INCIDENT_RECORD', $incident);
    expect($recorded)->toHaveCount(1);
    expect($recorded[0]->user_id)->toBe($recorder->id);
    expect($recorded[0]->new_value)->toMatchArray([
        'doc_no' => $loan->doc_no, 'loan_id' => $loan->id, 'lab_id' => $lab->id, 'line_no' => $line->line_no, 'loan_asset_id' => $asset->id,
        'kind' => 'DAMAGED', 'qty' => '1.000000', 'recorded_by' => $recorder->id, 'recorder_is_borrower' => false,
    ]);
    $returned = loanAudit('LOAN_RETURN', $loan);
    expect($returned)->toHaveCount(1);
    expect($returned[0]->new_value['pending_incident'])->toBeTrue()->and($returned[0]->new_value['closed'])->toBeFalse();
    expect($returned[0]->new_value['returned'])->toBe([['line_no' => $line->line_no, 'qty' => '1.000000', 'outcome' => 'DAMAGED', 'incident_id' => $incident->id]]);
    foreach ([$recorded[0], $returned[0]] as $audit) {
        expect(json_encode($audit->new_value, JSON_UNESCAPED_UNICODE))->not->toContain(INCIDENT_NOTE);
    }

    assertLoanChainIntact($a);
    $this->artisan('loans:verify')->assertExitCode(0);
});

test('a piece that will not come back is written down as LOST: MARK_LOST (no condition), unavailable, the line counts it as no longer out', function () {
    [, $asset, $borrower, $recorder] = incidentScene(10);
    $loan = handedOutLoan($borrower, $asset, 2);
    $line = $loan->lines()->firstOrFail();

    loanReturnPost($this, $recorder, $loan, [$line->line_no => ['qty_lost' => '2', 'incident_note' => 'ผู้ยืมแจ้งว่าหาไม่พบหลังออกภาคสนาม']])
        ->assertRedirect(route('loans.show', $loan))->assertSessionHas('status', __('loans.returned_incident'));

    $incident = LoanIncident::where('loan_id', $loan->id)->sole();
    expect([$incident->kind, $incident->qty, $incident->resolution])->toBe(['LOST', '2.000000', 'PENDING']);
    $line = $line->fresh();
    expect([$line->qty_returned_ok, $line->qty_returned_damaged, $line->qty_lost, $line->outstanding()])->toBe(['0.000000', '0.000000', '2.000000', '0.000000']);

    $a = $asset->fresh();
    expect([$a->qty_total, $a->qty_on_loan, $a->qty_unavailable, $a->available()])->toBe(['10.000000', '0.000000', '2.000000', '8.000000']);
    $row = LoanMovement::where('incident_id', $incident->id)->sole();
    expect([$row->movement_type, $row->qty, $row->condition_recorded, $row->qty_on_loan_after, $row->qty_unavailable_after, $row->created_by])
        ->toBe(['MARK_LOST', '2.000000', null, '0.000000', '2.000000', $recorder->id]);

    // Nothing is with the borrower any more, but the document waits for the result (BR-L12).
    expect($loan->fresh()->status)->toBe('PARTIALLY_RETURNED')->and($loan->fresh()->closed_at)->toBeNull();
    assertLoanChainIntact($a);
    $this->artisan('loans:verify')->assertExitCode(0);
});

test('one return can mix usable, damaged and lost pieces on the same line and on several lines: one movement and one incident per part, all in one action', function () {
    [$lab, $pool, $borrower, $recorder] = incidentScene(10);
    $second = loanExtraAsset($lab, 6);
    $loan = handedOutLoan($borrower, [$pool, $second], 5);
    [$lineA, $lineB] = $loan->lines()->get()->all();

    loanReturnPost($this, $recorder, $loan, [
        // 5 out: 2 usable, 1 damaged, 1 lost, 1 stays with the borrower.
        $lineA->line_no => ['qty' => '2', 'condition' => 'GOOD', 'qty_damaged' => '1', 'qty_lost' => '1', 'incident_note' => INCIDENT_NOTE],
        // 5 out: 3 usable, 2 damaged.
        $lineB->line_no => ['qty' => '3', 'condition' => 'FAIR', 'note' => WEAR_NOTE, 'qty_damaged' => '2', 'incident_note' => 'ฝาครอบแตกสองชิ้น ใช้งานต่อไม่ได้'],
    ])->assertRedirect(route('loans.show', $loan))->assertSessionHas('status', __('loans.returned_incident'));

    $lineA = $lineA->fresh();
    $lineB = $lineB->fresh();
    expect([$lineA->qty_returned_ok, $lineA->qty_returned_damaged, $lineA->qty_lost, $lineA->outstanding()])->toBe(['2.000000', '1.000000', '1.000000', '1.000000']);
    expect([$lineB->qty_returned_ok, $lineB->qty_returned_damaged, $lineB->qty_lost, $lineB->outstanding()])->toBe(['3.000000', '2.000000', '0.000000', '0.000000']);

    $incidents = LoanIncident::where('loan_id', $loan->id)->orderBy('id')->get();
    expect($incidents->map(fn ($i) => [$i->loan_line_id, $i->kind, $i->qty, $i->resolution])->all())->toBe([
        [$lineA->id, 'DAMAGED', '1.000000', 'PENDING'],
        [$lineA->id, 'LOST', '1.000000', 'PENDING'],
        [$lineB->id, 'DAMAGED', '2.000000', 'PENDING'],
    ]);
    expect($incidents->pluck('recorded_by')->unique()->all())->toBe([$recorder->id]);

    $p = $pool->fresh();
    $s = $second->fresh();
    expect([$p->qty_total, $p->qty_on_loan, $p->qty_unavailable, $p->available()])->toBe(['10.000000', '1.000000', '2.000000', '7.000000']);
    expect([$s->qty_total, $s->qty_on_loan, $s->qty_unavailable, $s->available()])->toBe(['6.000000', '0.000000', '2.000000', '4.000000']);

    expect(LoanMovement::where('loan_id', $loan->id)->where('loan_asset_id', $pool->id)->orderBy('id')->pluck('movement_type')->all())
        ->toBe(['HANDOUT', 'RETURN_OK', 'RETURN_DAMAGED', 'MARK_LOST']);
    expect(LoanMovement::where('loan_id', $loan->id)->where('loan_asset_id', $second->id)->orderBy('id')->pluck('movement_type')->all())
        ->toBe(['HANDOUT', 'RETURN_OK', 'RETURN_DAMAGED']);
    // Only the incident rows carry an incident_id; the usable part is an ordinary RETURN_OK.
    expect(LoanMovement::where('loan_id', $loan->id)->whereNotNull('incident_id')->count())->toBe(3);
    expect(LoanMovement::where('loan_id', $loan->id)->where('movement_type', 'RETURN_OK')->whereNotNull('incident_id')->count())->toBe(0);

    // ONE return audit row for the whole action, listing every part.
    $audit = loanAudit('LOAN_RETURN', $loan);
    expect($audit)->toHaveCount(1);
    expect(collect($audit[0]->new_value['returned'])->pluck('outcome')->all())->toBe(['OK', 'DAMAGED', 'LOST', 'OK', 'DAMAGED']);
    expect(AuditLog::where('action', 'LOAN_INCIDENT_RECORD')->whereIn('entity_id', $incidents->pluck('id'))->count())->toBe(3);

    expect($loan->fresh()->status)->toBe('PARTIALLY_RETURNED');
    assertLoanChainIntact($p);
    assertLoanChainIntact($s);
    $this->artisan('loans:verify')->assertExitCode(0);

    // The last piece comes back usable: nothing is out, but three incidents wait, so the loan is still not closed.
    loanReturnPost($this, $recorder, $loan, [$lineA->line_no => ['qty' => '1', 'condition' => 'GOOD']])
        ->assertRedirect(route('loans.show', $loan))->assertSessionHas('status', __('loans.returned_partial'));
    expect($loan->fresh()->status)->toBe('PARTIALLY_RETURNED')->and($loan->fresh()->closed_at)->toBeNull();
    expect(loanAudit('LOAN_RETURN', $loan)->last()->new_value)->toMatchArray(['closed' => false, 'pending_incident' => true]);
    $this->artisan('loans:verify')->assertExitCode(0);
});

test('the older single-outcome shape still works: outcome DAMAGED / LOST makes `qty` an incident and `note` its description', function (string $outcome, string $kind, string $type) {
    [, $asset, $borrower, $recorder] = incidentScene(10);
    $loan = handedOutLoan($borrower, $asset, 2);
    $line = $loan->lines()->firstOrFail();

    loanReturnPost($this, $recorder, $loan, [$line->line_no => ['qty' => '1', 'condition' => 'DAMAGED', 'note' => INCIDENT_NOTE, 'outcome' => $outcome]])
        ->assertRedirect(route('loans.show', $loan))->assertSessionHas('status', __('loans.returned_incident'));

    $incident = LoanIncident::where('loan_id', $loan->id)->sole();
    expect([$incident->kind, $incident->qty, $incident->description, $incident->resolution])->toBe([$kind, '1.000000', INCIDENT_NOTE, 'PENDING']);
    expect(incidentMovementTypes($incident))->toBe([$type]);
    // Nothing came back as an ordinary return.
    expect($line->fresh()->qty_returned_ok)->toBe('0.000000');
    expect(LoanMovement::where('loan_id', $loan->id)->where('movement_type', 'RETURN_OK')->count())->toBe(0);
    expect($asset->fresh()->qty_unavailable)->toBe('1.000000')->and($asset->fresh()->qty_on_loan)->toBe('1.000000');
    $this->artisan('loans:verify')->assertExitCode(0);
})->with([
    'DAMAGED' => ['DAMAGED', 'DAMAGED', 'RETURN_DAMAGED'],
    'LOST' => ['LOST', 'LOST', 'MARK_LOST'],
]);

test('the single-outcome shape in the service: case and blanks do not matter, an explicit incident_note wins over note, and its quantity adds to qty_damaged / qty_lost', function () {
    [, $asset, $borrower, $recorder] = incidentScene(10);
    $loan = handedOutLoan($borrower, $asset, 4);
    $service = app(LoanCirculationService::class);

    $service->receiveReturn($recorder->fresh(), $loan, [1 => ['qty' => '1', 'outcome' => ' lost ', 'note' => 'โน้ตของการคืนปกติยาวพอ', 'incident_note' => INCIDENT_NOTE]]);
    $first = LoanIncident::where('loan_id', $loan->id)->sole();
    expect([$first->kind, $first->qty, $first->description])->toBe(['LOST', '1.000000', INCIDENT_NOTE]);

    $service->receiveReturn($recorder->fresh(), $loan->fresh(), [1 => ['qty' => '1', 'outcome' => 'damaged', 'qty_damaged' => '1', 'note' => INCIDENT_NOTE]]);
    $second = LoanIncident::where('loan_id', $loan->id)->where('id', '!=', $first->id)->sole();
    expect([$second->kind, $second->qty, $second->description])->toBe(['DAMAGED', '2.000000', INCIDENT_NOTE]);

    expect($loan->lines()->firstOrFail()->outstanding())->toBe('1.000000');
    $this->artisan('loans:verify')->assertExitCode(0);
});

test('an incident needs a description of at least 10 characters, on the page and in the service; nothing is written without one', function () {
    [, $asset, $borrower, $recorder] = incidentScene(10);
    $loan = handedOutLoan($borrower, $asset, 3);
    $service = app(LoanCirculationService::class);
    $before = loanIncidentState($loan);
    $message = __('loans.error.incident_description_required');

    $bad = [
        'no description' => ['qty_damaged' => '1'],
        'blank description' => ['qty_lost' => '1', 'incident_note' => '          '],
        '9 characters' => ['qty_damaged' => '1', 'incident_note' => '123456789'],
        '9 characters padded with blanks' => ['qty_lost' => '1', 'incident_note' => '  123456789   '],
        // The note of the usable part is NOT the incident's description in the new shape.
        'only the return note' => ['qty' => '1', 'condition' => 'GOOD', 'note' => INCIDENT_NOTE, 'qty_damaged' => '1'],
        'single-outcome shape with a short note' => ['qty' => '1', 'outcome' => 'DAMAGED', 'note' => 'สั้นไป'],
        'single-outcome shape without a note' => ['qty' => '1', 'outcome' => 'LOST'],
    ];
    foreach ($bad as $case => $row) {
        loanReturnPost($this, $recorder, $loan, [1 => $row])->assertSessionHasErrors(['return' => $message]);
        expect(fn () => $service->receiveReturn($recorder->fresh(), $loan, [1 => $row]))->toThrow(LoanDomainException::class, $message);
        expect(loanIncidentState($loan))->toBe($before, $case);
    }

    // Longer than the column: stopped by the Form Request (the service would cut it at 500).
    loanReturnPost($this, $recorder, $loan, [1 => ['qty_damaged' => '1', 'incident_note' => str_repeat('ก', 501)]])->assertSessionHasErrors('lines.1.incident_note');
    expect(loanIncidentState($loan))->toBe($before);

    // Exactly 10 characters is enough.
    loanReturnPost($this, $recorder, $loan, [1 => ['qty_damaged' => '1', 'incident_note' => '1234567890']])->assertSessionHasNoErrors();
    expect(LoanIncident::where('loan_id', $loan->id)->sole()->description)->toBe('1234567890');
});

test('usable + damaged + lost can never exceed what the line still has out, and the quantities must be whole numbers; nothing is written', function () {
    [, $asset, $borrower, $recorder] = incidentScene(10);
    $loan = handedOutLoan($borrower, $asset, 3);
    $service = app(LoanCirculationService::class);
    $before = loanIncidentState($loan);

    $tooMuch = [
        ['qty_damaged' => '4', 'incident_note' => INCIDENT_NOTE],
        ['qty_lost' => '4', 'incident_note' => INCIDENT_NOTE],
        ['qty' => '2', 'condition' => 'GOOD', 'qty_damaged' => '1', 'qty_lost' => '1', 'incident_note' => INCIDENT_NOTE],
        ['qty' => '3', 'condition' => 'GOOD', 'qty_lost' => '1', 'incident_note' => INCIDENT_NOTE],
        ['qty' => '2', 'outcome' => 'DAMAGED', 'note' => INCIDENT_NOTE, 'qty_damaged' => '2'],
    ];
    foreach ($tooMuch as $row) {
        loanReturnPost($this, $recorder, $loan, [1 => $row])->assertSessionHasErrors(['return' => __('loans.error.return_too_much')]);
        expect(fn () => $service->receiveReturn($recorder->fresh(), $loan, [1 => $row]))->toThrow(LoanDomainException::class, __('loans.error.return_too_much'));
    }

    foreach (['1.5', '-1', 'abc', '1e2', '0x1'] as $bad) {
        foreach (['qty_damaged', 'qty_lost'] as $field) {
            loanReturnPost($this, $recorder, $loan, [1 => [$field => $bad, 'incident_note' => INCIDENT_NOTE]])->assertSessionHasErrors("lines.1.{$field}");
            expect(fn () => $service->receiveReturn($recorder->fresh(), $loan, [1 => [$field => $bad, 'incident_note' => INCIDENT_NOTE]]))
                ->toThrow(LoanDomainException::class, __('loans.error.qty_whole'));
        }
    }

    // Zero / blank in the incident boxes means "none": with nothing else on the row there is nothing to take back.
    loanReturnPost($this, $recorder, $loan, [1 => ['qty_damaged' => '0', 'qty_lost' => '', 'incident_note' => INCIDENT_NOTE]])
        ->assertSessionHasErrors(['return' => __('loans.error.return_nothing')]);
    // A line this document does not have.
    loanReturnPost($this, $recorder, $loan, [7 => ['qty_lost' => '1', 'incident_note' => INCIDENT_NOTE]])->assertSessionHasErrors(['return' => __('loans.error.line_mismatch')]);

    expect(loanIncidentState($loan))->toBe($before);
    expect(LoanIncident::where('loan_id', $loan->id)->count())->toBe(0);
});

test('a return is all or nothing: when a later part is refused, the parts before it (movement, incident, line, asset) are rolled back', function () {
    [$lab, $pool, $borrower, $recorder] = incidentScene(10);
    $second = loanExtraAsset($lab, 6);
    $loan = handedOutLoan($borrower, [$pool, $second], 2);
    [$lineA, $lineB] = $loan->lines()->get()->all();
    $service = app(LoanCirculationService::class);

    // (1) The second line's incident has no description: found before anything is written.
    $before = loanIncidentState($loan);
    $rows = [
        $lineA->line_no => ['qty' => '1', 'condition' => 'GOOD', 'qty_damaged' => '1', 'incident_note' => INCIDENT_NOTE],
        $lineB->line_no => ['qty_lost' => '1'],
    ];
    loanReturnPost($this, $recorder, $loan, $rows)->assertSessionHasErrors(['return' => __('loans.error.incident_description_required')]);
    expect(loanIncidentState($loan))->toBe($before);

    // (2) The ledger refuses the SECOND asset only after the first asset's rows were written (its counter was edited by
    //     hand, so it "has nothing out"): the whole return is undone, including the first asset's incident.
    DB::table('loan_assets')->where('id', $second->id)->update(['qty_on_loan' => 0]);
    $before = loanIncidentState($loan);
    $rows = [
        $lineA->line_no => ['qty' => '1', 'condition' => 'GOOD', 'qty_damaged' => '1', 'incident_note' => INCIDENT_NOTE],
        $lineB->line_no => ['qty_lost' => '1', 'incident_note' => INCIDENT_NOTE],
    ];
    expect($pool->id)->toBeLessThan($second->id);   // the pool's parts are written first
    loanReturnPost($this, $recorder, $loan, $rows)->assertSessionHasErrors(['return' => __('loans.error.return_too_much')]);
    expect(fn () => $service->receiveReturn($recorder->fresh(), $loan, $rows))->toThrow(LoanDomainException::class, __('loans.error.return_too_much'));

    expect(loanIncidentState($loan))->toBe($before);
    expect(LoanIncident::where('loan_id', $loan->id)->count())->toBe(0);
    expect($pool->fresh()->qty_unavailable)->toBe('0.000000')->and($pool->fresh()->qty_on_loan)->toBe('2.000000');
    assertLoanChainIntact($pool->fresh());
});

test('the ledger writes RETURN_DAMAGED / MARK_LOST only for an incident row: by name through takeBack() they are refused, and takeBackIncident() checks the incident', function () {
    [, $asset, $borrower, $recorder, $resolver] = incidentScene(10);
    $loan = handedOutLoan($borrower, $asset, 3);
    $line = $loan->lines()->firstOrFail();
    $before = loanIncidentState($loan);

    foreach (['RETURN_DAMAGED', 'MARK_LOST'] as $type) {
        expect(fn () => loanService()->takeBack($recorder->fresh(), $asset->fresh(), $loan, $line, $type, '1.000000', 'DAMAGED', INCIDENT_NOTE))
            ->toThrow(LoanDomainException::class, __('loans.error.incident_not_available'));
    }
    foreach (['HANDOUT', 'WRITE_OFF', 'REPAIR_DONE', 'REPLACE_IN', 'ADJUST_IN', ''] as $type) {
        expect(fn () => loanService()->takeBack($recorder->fresh(), $asset->fresh(), $loan, $line, $type, '1.000000', 'GOOD', null))
            ->toThrow(LoanDomainException::class, __('loans.error.return_type_invalid'));
    }
    // A word that is no return type at all: the page and the service.
    loanReturnPost($this, $recorder, $loan, [$line->line_no => ['qty' => '1', 'condition' => 'GOOD', 'outcome' => 'STOLEN']])->assertSessionHasErrors("lines.{$line->line_no}.outcome");
    expect(fn () => app(LoanCirculationService::class)->receiveReturn($recorder->fresh(), $loan, [$line->line_no => ['qty' => '1', 'condition' => 'GOOD', 'outcome' => 'STOLEN']]))
        ->toThrow(LoanDomainException::class, __('loans.error.return_type_invalid'));
    expect(loanIncidentState($loan))->toBe($before);

    // A real PENDING incident (1 piece) for the direct ledger calls below.
    $otherLoan = handedOutLoan($borrower, $asset, 1);
    $incident = recordLoanIncident($loan, $recorder, 'DAMAGED', 1);
    $incident->setRelation('line', $line->fresh());
    $before = loanIncidentState($loan);

    // takeBackIncident() is called by LoanIncidentService::record() only. What it checks by itself: the caller is the
    // person on the incident, the incident belongs to this loan / line / asset, and the caller works this counter.
    expect(fn () => loanService()->takeBackIncident($resolver->fresh(), $asset->fresh(), $loan, $line->fresh(), $incident, null))
        ->toThrow(LoanDomainException::class, __('loans.error.line_mismatch'));
    expect(fn () => loanService()->takeBackIncident($recorder->fresh(), $asset->fresh(), $otherLoan, $otherLoan->lines()->firstOrFail(), $incident, null))
        ->toThrow(LoanDomainException::class, __('loans.error.line_mismatch'));
    $outsider = scientistUser(['lab_id' => makeLab()->id]);
    expect(fn () => loanService()->takeBackIncident($outsider, $asset->fresh(), $loan, $line->fresh(), $incident, null))
        ->toThrow(LoanDomainException::class, __('loans.error.handler_not_eligible'));

    expect(loanIncidentState($loan))->toBe($before);
});

// --- loans:verify --------------------------------------------------------------------------------------------------

test('loans:verify catches a hand-edited "unavailable" counter, incident or line while an incident is PENDING', function (string $tamper, string $expected) {
    [, $asset, $borrower, $recorder, $resolver] = incidentScene(10);
    $loan = handedOutLoan($borrower, $asset, 3);
    $incident = recordLoanIncident($loan, $recorder, 'LOST', 2);
    $this->artisan('loans:verify')->assertExitCode(0);

    match ($tamper) {
        // The pieces "come back" to the shelf without a result.
        'unavailable set to 0' => DB::table('loan_assets')->where('id', $asset->id)->update(['qty_unavailable' => 0]),
        'unavailable raised' => DB::table('loan_assets')->where('id', $asset->id)->update(['qty_unavailable' => 5]),
        // The incident is made smaller / bigger than what the ledger moved.
        'incident quantity lowered' => DB::table('loan_incidents')->where('id', $incident->id)->update(['qty' => 1]),
        'incident quantity raised' => DB::table('loan_incidents')->where('id', $incident->id)->update(['qty' => 3]),
        // The incident is "resolved" in the table, with no movement.
        'incident closed by hand' => DB::table('loan_incidents')->where('id', $incident->id)->update([
            'resolution' => 'WAIVED', 'disposition' => 'WRITE_OFF', 'resolved_by' => $resolver->id, 'resolved_at' => now(),
        ]),
        // The line is edited so the lost pieces look returned / still out.
        'line: lost pieces erased' => DB::table('loan_lines')->where('loan_id', $loan->id)->update(['qty_lost' => 0]),
        'line: more lost than recorded' => DB::table('loan_lines')->where('loan_id', $loan->id)->update(['qty_lost' => 3]),
        // The total is lowered as if the pieces had been written off.
        'total lowered' => DB::table('loan_assets')->where('id', $asset->id)->update(['qty_total' => 8]),
    };

    $this->artisan('loans:verify')
        ->expectsOutputToContain(__('loans.verify_cmd.broken', ['problem' => "asset #{$asset->id}: {$expected}"]))
        ->assertExitCode(1);
})->with([
    'unavailable set to 0' => ['unavailable set to 0', 'unavailable quantity differs from the pending incidents'],
    'unavailable raised' => ['unavailable raised', 'unavailable quantity differs from the pending incidents'],
    'incident quantity lowered' => ['incident quantity lowered', 'unavailable quantity differs from the pending incidents'],
    'incident quantity raised' => ['incident quantity raised', 'unavailable quantity differs from the pending incidents'],
    'incident closed by hand' => ['incident closed by hand', 'unavailable quantity differs from the pending incidents'],
    'line: lost pieces erased' => ['line: lost pieces erased', 'on-loan quantity differs from the open loan documents'],
    'line: more lost than recorded' => ['line: more lost than recorded', 'on-loan quantity differs from the open loan documents'],
    'total lowered' => ['total lowered', 'quantities differ from the latest movement'],
]);

test('loans:verify passes after every kind of result and catches a counter edited by hand afterwards', function () {
    [$lab, $asset, $borrower, $recorder, $resolver, $receiver] = incidentScene(10);
    $unit = loanExtraAsset($lab, 1, null, 'UNIT');
    $service = app(LoanIncidentService::class);
    $loan = handedOutLoan($borrower, [$asset, $unit], 1);
    $big = handedOutLoan($borrower, $asset, 6);

    $repaired = recordLoanIncident($big, $recorder, 'DAMAGED', 1);
    $compensated = recordLoanIncident($big, $recorder, 'DAMAGED', 2);
    $replaced = recordLoanIncident($big, $recorder, 'LOST', 1);
    $unitLost = recordLoanIncident($loan, $recorder, 'LOST', 1, $loan->lines()->where('loan_asset_id', $unit->id)->firstOrFail()->line_no);
    $this->artisan('loans:verify')->assertExitCode(0);

    $service->resolve($resolver->fresh(), $repaired, ['resolution' => 'REPAIRED', 'condition_after' => 'GOOD', 'resolution_note' => 'ซ่อมแล้วใช้งานได้ตามปกติ']);
    $this->artisan('loans:verify')->assertExitCode(0);

    attachLoanIncidentFile($compensated, $recorder);
    $service->resolve($resolver->fresh(), $compensated, loanResolveData('COMPENSATED', ['disposition' => 'WRITE_OFF']));
    $this->artisan('loans:verify')->assertExitCode(0);

    attachLoanIncidentFile($replaced, $recorder);
    $service->resolve($resolver->fresh(), $replaced, loanResolveData('REPLACED', ['receiver_id' => $receiver->id]));
    $this->artisan('loans:verify')->assertExitCode(0);

    attachLoanIncidentFile($unitLost, $recorder);
    $service->resolve($resolver->fresh(), $unitLost, loanResolveData('WAIVED'));
    $this->artisan('loans:verify')->assertExitCode(0);

    // 10 - 2 written off (the replaced piece went out and came back in) = 8; 3 still out (2 on $big, 1 on $loan).
    $a = $asset->fresh();
    expect([$a->qty_total, $a->qty_on_loan, $a->qty_unavailable, $a->available()])->toBe(['8.000000', '3.000000', '0.000000', '5.000000']);
    assertLoanChainIntact($a);
    assertLoanChainIntact($unit->fresh());

    // Afterwards: a leftover "unavailable" piece nobody can explain, or a total that grew back.
    DB::table('loan_assets')->where('id', $asset->id)->update(['qty_unavailable' => 1]);
    $this->artisan('loans:verify')->expectsOutputToContain("asset #{$asset->id}: unavailable quantity differs from the pending incidents")->assertExitCode(1);
    DB::table('loan_assets')->where('id', $asset->id)->update(['qty_unavailable' => 0, 'qty_total' => 10]);
    $this->artisan('loans:verify')->expectsOutputToContain("asset #{$asset->id}: quantities differ from the latest movement")->assertExitCode(1);
    DB::table('loan_assets')->where('id', $asset->id)->update(['qty_total' => 8]);
    $this->artisan('loans:verify')->assertExitCode(0);
});

// --- results: back into service ------------------------------------------------------------------------------------

test('REPAIRED: the damaged pieces go back into service (REPAIR_DONE), a note and the condition are enough, the value of the day is kept on the incident', function () {
    [$lab, $asset, $borrower, $recorder, $resolver] = incidentScene(10);
    setLoanAssetValue($asset, '1500.50');
    $admin = adminUser();
    $loan = handedOutLoan($borrower, $asset, 3);
    $incident = recordLoanIncident($loan, $recorder, 'DAMAGED', 2);
    expect($asset->fresh()->available())->toBe('7.000000');

    loanResolvePost($this, $resolver, $incident, ['resolution' => 'REPAIRED', 'condition_after' => 'FAIR', 'resolution_note' => '  เปลี่ยนขาตั้งใหม่ ใช้งานได้ตามปกติ  '])
        ->assertRedirect(route('loans.incidents.show', $incident))->assertSessionHas('status', __('loans.incident_resolved'));

    $done = $incident->fresh();
    expect([$done->resolution, $done->disposition, $done->resolved_by, $done->external_ref, $done->resolution_note, $done->value_snapshot])
        ->toBe(['REPAIRED', 'RETURN_TO_SERVICE', $resolver->id, null, 'เปลี่ยนขาตั้งใหม่ ใช้งานได้ตามปกติ', '3001.00']);
    expect($done->resolved_at)->not->toBeNull()->and($done->isPending())->toBeFalse();
    // What was recorded at the counter is untouched.
    expect([$done->kind, $done->qty, $done->description, $done->recorded_by])->toBe(['DAMAGED', '2.000000', 'หน้าจอแตกร้าวใช้งานต่อไม่ได้', $recorder->id]);

    $a = $asset->fresh();
    expect([$a->qty_total, $a->qty_on_loan, $a->qty_unavailable, $a->available(), $a->status])->toBe(['10.000000', '1.000000', '0.000000', '9.000000', 'ACTIVE']);

    expect(incidentMovementTypes($incident))->toBe(['RETURN_DAMAGED', 'REPAIR_DONE']);
    $row = LoanMovement::where('incident_id', $incident->id)->where('movement_type', 'REPAIR_DONE')->sole();
    expect([$row->qty, $row->condition_recorded, $row->loan_id, $row->loan_line_id, $row->loan_asset_id, $row->lab_id])
        ->toBe(['2.000000', 'FAIR', $loan->id, $incident->loan_line_id, $asset->id, $lab->id]);
    expect([$row->qty_total_after, $row->qty_on_loan_after, $row->qty_unavailable_after])->toBe(['10.000000', '1.000000', '0.000000']);
    expect([$row->created_by, $row->approved_by, $row->external_ref, $row->note])->toBe([$resolver->id, null, null, 'เปลี่ยนขาตั้งใหม่ ใช้งานได้ตามปกติ']);

    $audit = loanIncidentAudit('LOAN_INCIDENT_RESOLVE', $incident);
    expect($audit)->toHaveCount(1);
    expect($audit[0]->user_id)->toBe($resolver->id)->and($audit[0]->old_value)->toBe(['resolution' => 'PENDING']);
    expect($audit[0]->new_value)->toMatchArray([
        'doc_no' => $loan->doc_no, 'loan_id' => $loan->id, 'lab_id' => $lab->id, 'kind' => 'DAMAGED', 'qty' => '2.000000',
        'resolution' => 'REPAIRED', 'disposition' => 'RETURN_TO_SERVICE', 'condition_after' => 'FAIR', 'external_ref' => null,
        'recorded_by' => $recorder->id, 'resolved_by' => $resolver->id, 'replacement_received_by' => null, 'resolver_is_admin' => false, 'loan_closed' => false,
    ]);
    // Nothing left the register: no write-off record, nobody outside the branch is told.
    expect(AuditLog::whereIn('action', ['LOAN_WRITE_OFF', 'LOAN_REPLACE_IN'])->where('entity_type', LoanAsset::class)->where('entity_id', $asset->id)->count())->toBe(0);
    expect(loanNotices(LoanNotifier::TYPE_WRITE_OFF))->toHaveCount(0);
    expect(loanMailCount($admin))->toBe(0);

    // One piece is still with the borrower.
    expect($loan->fresh()->status)->toBe('PARTIALLY_RETURNED');
    assertLoanChainIntact($a);
    $this->artisan('loans:verify')->assertExitCode(0);

    // The repaired pieces can be lent again.
    $next = handedOutLoan($borrower, $asset, 9);
    expect($next->status)->toBe('ON_LOAN')->and($asset->fresh()->available())->toBe('0.000000');
});

test('REPAIRED is for a DAMAGED piece that goes back into service only: the note, the condition and a real reference (when one is given) are required', function () {
    [, $asset, $borrower, $recorder, $resolver] = incidentScene(10);
    $loan = handedOutLoan($borrower, $asset, 3);
    $incident = recordLoanIncident($loan, $recorder, 'DAMAGED', 1);
    $service = app(LoanIncidentService::class);
    $before = loanIncidentState($loan);
    $note = 'ซ่อมแล้วใช้งานได้ตามปกติ';

    $cases = [
        // [payload, the service's message, the page's error key]
        'no condition' => [['resolution' => 'REPAIRED', 'resolution_note' => $note], 'loans.error.incident_condition_after', 'incident'],
        'written off' => [['resolution' => 'REPAIRED', 'disposition' => 'WRITE_OFF', 'condition_after' => 'GOOD', 'resolution_note' => $note], 'loans.error.incident_resolution_invalid', 'incident'],
        'a reference of 4 characters' => [['resolution' => 'REPAIRED', 'condition_after' => 'GOOD', 'resolution_note' => $note, 'external_ref' => 'ab12'], 'loans.error.incident_ref_required', 'incident'],
        'condition DAMAGED' => [['resolution' => 'REPAIRED', 'condition_after' => 'DAMAGED', 'resolution_note' => $note], 'loans.error.incident_condition_after', 'condition_after'],
        'no note' => [['resolution' => 'REPAIRED', 'condition_after' => 'GOOD'], 'loans.error.incident_note_required', 'resolution_note'],
        'a note of 9 characters' => [['resolution' => 'REPAIRED', 'condition_after' => 'GOOD', 'resolution_note' => '123456789'], 'loans.error.incident_note_required', 'resolution_note'],
        'a blank note' => [['resolution' => 'REPAIRED', 'condition_after' => 'GOOD', 'resolution_note' => '            '], 'loans.error.incident_note_required', 'resolution_note'],
    ];
    foreach ($cases as $case => [$payload, $message, $errorKey]) {
        loanResolvePost($this, $resolver, $incident, $payload)->assertSessionHasErrors($errorKey);
        expect(fn () => $service->resolve($resolver->fresh(), $incident, array_merge(['resolution_note' => null], $payload)))
            ->toThrow(LoanDomainException::class, __($message));
        expect(loanIncidentState($loan))->toBe($before, $case);
    }
    foreach (['', 'PENDING', 'STOLEN', 'repaired '] as $word) {
        loanResolvePost($this, $resolver, $incident, ['resolution' => $word, 'condition_after' => 'GOOD', 'resolution_note' => $note])->assertSessionHasErrors('resolution');
    }
    foreach (['PENDING', 'STOLEN'] as $word) {
        expect(fn () => $service->resolve($resolver->fresh(), $incident, ['resolution' => $word, 'condition_after' => 'GOOD', 'resolution_note' => $note]))
            ->toThrow(LoanDomainException::class, __('loans.error.incident_resolution_invalid'));
    }
    expect(fn () => $service->resolve($resolver->fresh(), $incident, ['resolution' => 'REPAIRED', 'disposition' => 'SCRAP', 'condition_after' => 'GOOD', 'resolution_note' => $note]))
        ->toThrow(LoanDomainException::class, __('loans.error.incident_resolution_invalid'));
    expect(loanIncidentState($loan))->toBe($before);

    // A reference of exactly 5 characters and a note of exactly 10 pass.
    loanResolvePost($this, $resolver, $incident, ['resolution' => 'REPAIRED', 'disposition' => 'RETURN_TO_SERVICE', 'condition_after' => 'GOOD', 'resolution_note' => '1234567890', 'external_ref' => 'ab123'])
        ->assertSessionHasNoErrors();
    expect($incident->fresh()->resolution)->toBe('REPAIRED')->and($incident->fresh()->external_ref)->toBe('ab123');
});

test('COMPENSATED / WAIVED of a damaged piece that stays in service: reference + note + condition, no letter needed, nothing leaves the register', function (string $resolution) {
    [, $asset, $borrower, $recorder, $resolver] = incidentScene(10);
    $admin = adminUser();
    $loan = handedOutLoan($borrower, $asset, 2);
    $incident = recordLoanIncident($loan, $recorder, 'DAMAGED', 1);
    $service = app(LoanIncidentService::class);
    $before = loanIncidentState($loan);

    // The form must say what happens to the piece.
    loanResolvePost($this, $resolver, $incident, loanResolveData($resolution))->assertSessionHasErrors(['incident' => __('loans.error.incident_disposition_required')]);
    expect(fn () => $service->resolve($resolver->fresh(), $incident, loanResolveData($resolution)))->toThrow(LoanDomainException::class, __('loans.error.incident_disposition_required'));
    // Back in service: the condition after the check, and BOTH the reference and the note.
    $ok = loanResolveData($resolution, ['disposition' => 'RETURN_TO_SERVICE', 'condition_after' => 'GOOD']);
    loanResolvePost($this, $resolver, $incident, array_merge($ok, ['condition_after' => null]))->assertSessionHasErrors(['incident' => __('loans.error.incident_condition_after')]);
    loanResolvePost($this, $resolver, $incident, array_merge($ok, ['external_ref' => null]))->assertSessionHasErrors(['incident' => __('loans.error.incident_ref_required')]);
    loanResolvePost($this, $resolver, $incident, array_merge($ok, ['external_ref' => '    ']))->assertSessionHasErrors(['incident' => __('loans.error.incident_ref_required')]);
    loanResolvePost($this, $resolver, $incident, array_merge($ok, ['external_ref' => '1234']))->assertSessionHasErrors(['incident' => __('loans.error.incident_ref_required')]);
    loanResolvePost($this, $resolver, $incident, array_merge($ok, ['resolution_note' => null]))->assertSessionHasErrors('resolution_note');
    expect(fn () => $service->resolve($resolver->fresh(), $incident, array_merge($ok, ['resolution_note' => null])))->toThrow(LoanDomainException::class, __('loans.error.incident_note_required'));
    expect(loanIncidentState($loan))->toBe($before);

    loanResolvePost($this, $resolver, $incident, $ok)->assertRedirect(route('loans.incidents.show', $incident))->assertSessionHasNoErrors();

    $done = $incident->fresh();
    expect([$done->resolution, $done->disposition, $done->external_ref, $done->resolved_by, $done->value_snapshot])->toBe([$resolution, 'RETURN_TO_SERVICE', 'อว 7301/0123', $resolver->id, null]);
    expect(incidentMovementTypes($incident))->toBe(['RETURN_DAMAGED', 'REPAIR_DONE']);
    $a = $asset->fresh();
    expect([$a->qty_total, $a->qty_on_loan, $a->qty_unavailable, $a->available()])->toBe(['10.000000', '1.000000', '0.000000', '9.000000']);
    expect(loanNotices(LoanNotifier::TYPE_WRITE_OFF))->toHaveCount(0)->and(loanMailCount($admin))->toBe(0);
    $this->artisan('loans:verify')->assertExitCode(0);
})->with(['COMPENSATED', 'WAIVED']);

// --- results: written off ------------------------------------------------------------------------------------------

test('COMPENSATED / WAIVED with a write-off: needs the attached letter (LOAN_DOC of THIS incident), a reference and a note; WRITE_OFF lowers the total', function (string $kind, string $resolution, ?string $disposition) {
    [$lab, $asset, $borrower, $recorder, $resolver] = incidentScene(10);
    setLoanAssetValue($asset, '250.00');
    $admin = adminUser();
    $loan = handedOutLoan($borrower, $asset, 4);
    $incident = recordLoanIncident($loan, $recorder, $kind, 2);
    $other = recordLoanIncident($loan, $recorder, 'DAMAGED', 1);
    $service = app(LoanIncidentService::class);
    $payload = loanResolveData($resolution, array_filter(['disposition' => $disposition]));

    // No letter; a photo is not a letter; another incident's letter is not this one's.
    $before = loanIncidentState($loan);
    loanResolvePost($this, $resolver, $incident, $payload)->assertSessionHasErrors(['incident' => __('loans.error.incident_doc_required')]);
    attachLoanIncidentFile($incident, $recorder, 'PHOTO', 'photo.png');
    attachLoanIncidentFile($other, $recorder, 'LOAN_DOC');
    loanResolvePost($this, $resolver, $incident, $payload)->assertSessionHasErrors(['incident' => __('loans.error.incident_doc_required')]);
    expect(fn () => $service->resolve($resolver->fresh(), $incident, $payload))->toThrow(LoanDomainException::class, __('loans.error.incident_doc_required'));
    expect(loanIncidentState($loan))->toBe($before);

    attachLoanIncidentFile($incident, $recorder, 'LOAN_DOC');
    // With the letter: still both the reference and the note (never one without the other).
    loanResolvePost($this, $resolver, $incident, array_merge($payload, ['external_ref' => null]))->assertSessionHasErrors(['incident' => __('loans.error.incident_ref_required')]);
    loanResolvePost($this, $resolver, $incident, array_merge($payload, ['external_ref' => 'ab 1']))->assertSessionHasErrors(['incident' => __('loans.error.incident_ref_required')]);
    loanResolvePost($this, $resolver, $incident, array_merge($payload, ['resolution_note' => null]))->assertSessionHasErrors('resolution_note');
    loanResolvePost($this, $resolver, $incident, array_merge($payload, ['resolution_note' => 'สั้นไป']))->assertSessionHasErrors('resolution_note');
    expect(fn () => $service->resolve($resolver->fresh(), $incident, array_merge($payload, ['resolution_note' => 'สั้นไป'])))->toThrow(LoanDomainException::class, __('loans.error.incident_note_required'));
    expect(loanIncidentState($loan))->toBe($before);

    loanResolvePost($this, $resolver, $incident, $payload)->assertRedirect(route('loans.incidents.show', $incident))->assertSessionHas('status', __('loans.incident_resolved'));

    $done = $incident->fresh();
    expect([$done->resolution, $done->disposition, $done->external_ref, $done->resolution_note, $done->resolved_by, $done->value_snapshot])
        ->toBe([$resolution, 'WRITE_OFF', 'อว 7301/0123', 'ผู้บริหารพิจารณาตามบันทึกข้อความแล้ว', $resolver->id, '500.00']);

    // 2 pieces left the register for good; the other incident's piece is still unavailable; 1 is still out.
    $a = $asset->fresh();
    expect([$a->qty_total, $a->qty_on_loan, $a->qty_unavailable, $a->available(), $a->status])->toBe(['8.000000', '1.000000', '1.000000', '6.000000', 'ACTIVE']);

    expect(incidentMovementTypes($incident))->toBe([$kind === 'LOST' ? 'MARK_LOST' : 'RETURN_DAMAGED', 'WRITE_OFF']);
    $row = LoanMovement::where('incident_id', $incident->id)->where('movement_type', 'WRITE_OFF')->sole();
    expect([$row->qty, $row->condition_recorded, $row->loan_id, $row->loan_line_id])->toBe(['2.000000', null, $loan->id, $incident->loan_line_id]);
    expect([$row->qty_total_after, $row->qty_on_loan_after, $row->qty_unavailable_after])->toBe(['8.000000', '1.000000', '1.000000']);
    expect([$row->created_by, $row->approved_by, $row->external_ref, $row->note])->toBe([$resolver->id, null, 'อว 7301/0123', 'ผู้บริหารพิจารณาตามบันทึกข้อความแล้ว']);

    $writeOff = AuditLog::where('action', 'LOAN_WRITE_OFF')->where('entity_type', LoanAsset::class)->where('entity_id', $asset->id)->get();
    expect($writeOff)->toHaveCount(1);
    expect($writeOff[0]->user_id)->toBe($resolver->id)->and($writeOff[0]->old_value)->toBe(['qty_total' => '10.000000']);
    expect($writeOff[0]->new_value)->toMatchArray([
        'qty_total' => '8.000000', 'qty' => '2.000000', 'loan_id' => $loan->id, 'doc_no' => $loan->doc_no, 'incident_id' => $incident->id,
        'kind' => $kind, 'external_ref' => 'อว 7301/0123', 'recorded_by' => $recorder->id, 'retired_empty' => false,
    ]);
    expect(loanIncidentAudit('LOAN_INCIDENT_RESOLVE', $incident)[0]->new_value)->toMatchArray([
        'resolution' => $resolution, 'disposition' => 'WRITE_OFF', 'condition_after' => null, 'external_ref' => 'อว 7301/0123', 'loan_closed' => false,
    ]);
    expect(AuditLog::where('action', 'LOAN_REPLACE_IN')->where('entity_type', LoanAsset::class)->where('entity_id', $asset->id)->count())->toBe(0);

    // FR-LN-N9: somebody outside the branch learns of every write-off.
    expect(Notification::where('user_id', $admin->id)->where('type', LoanNotifier::TYPE_WRITE_OFF)->count())->toBe(1);
    expect(loanMailCount($admin))->toBe(1);

    expect($loan->fresh()->status)->toBe('PARTIALLY_RETURNED');
    expect($lab->id)->toBe($a->lab_id);
    assertLoanChainIntact($a);
    $this->artisan('loans:verify')->assertExitCode(0);
})->with([
    'DAMAGED, COMPENSATED, written off' => ['DAMAGED', 'COMPENSATED', 'WRITE_OFF'],
    'DAMAGED, WAIVED, written off' => ['DAMAGED', 'WAIVED', 'WRITE_OFF'],
    'LOST, COMPENSATED (disposition left blank)' => ['LOST', 'COMPENSATED', null],
    'LOST, WAIVED (disposition given)' => ['LOST', 'WAIVED', 'WRITE_OFF'],
]);

test('a LOST piece can never be REPAIRED or put back into service: refused on the page and in the service, and by the ledger itself', function () {
    [, $asset, $borrower, $recorder, $resolver] = incidentScene(10);
    $loan = handedOutLoan($borrower, $asset, 2);
    $incident = recordLoanIncident($loan, $recorder, 'LOST', 1);
    attachLoanIncidentFile($incident, $recorder);
    $service = app(LoanIncidentService::class);
    $before = loanIncidentState($loan);
    $message = __('loans.error.incident_lost_no_return');

    $payloads = [
        ['resolution' => 'REPAIRED', 'condition_after' => 'GOOD', 'resolution_note' => 'พบของแล้วและใช้งานได้'],
        loanResolveData('REPAIRED', ['disposition' => 'RETURN_TO_SERVICE', 'condition_after' => 'GOOD']),
        loanResolveData('REPAIRED', ['disposition' => 'WRITE_OFF']),
        loanResolveData('COMPENSATED', ['disposition' => 'RETURN_TO_SERVICE', 'condition_after' => 'GOOD']),
        loanResolveData('WAIVED', ['disposition' => 'RETURN_TO_SERVICE', 'condition_after' => 'FAIR']),
        loanResolveData('REPLACED', ['disposition' => 'RETURN_TO_SERVICE', 'receiver_id' => $recorder->id]),
    ];
    foreach ($payloads as $payload) {
        loanResolvePost($this, $resolver, $incident, $payload)->assertSessionHasErrors(['incident' => $message]);
        expect(fn () => $service->resolve($resolver->fresh(), $incident, $payload))->toThrow(LoanDomainException::class, $message);
    }
    // The ledger's own REPAIR_DONE refuses a LOST incident whoever calls it.
    $incident->setRelation('line', $loan->lines()->firstOrFail());
    expect(fn () => loanService()->repairDone($resolver->fresh(), $asset->fresh(), $loan->fresh(), $incident, 'GOOD', 'พบของแล้วและใช้งานได้'))
        ->toThrow(LoanDomainException::class, $message);

    expect(loanIncidentState($loan))->toBe($before);
    expect($incident->fresh()->isPending())->toBeTrue();
});

// --- results: REPLACED ---------------------------------------------------------------------------------------------

test('REPLACED: WRITE_OFF then REPLACE_IN in one action, by two different people — the resolver approves, a named counter person receives', function (string $kind) {
    [$lab, $asset, $borrower, $recorder, $resolver, $receiver] = incidentScene(10);
    setLoanAssetValue($asset, '99.99');
    $admin = adminUser();
    $loan = handedOutLoan($borrower, $asset, 3);
    $incident = recordLoanIncident($loan, $recorder, $kind, 2);
    attachLoanIncidentFile($incident, $resolver);

    loanResolvePost($this, $resolver, $incident, loanResolveData('REPLACED', ['receiver_id' => $receiver->id]))
        ->assertRedirect(route('loans.incidents.show', $incident))->assertSessionHas('status', __('loans.incident_resolved'));

    $done = $incident->fresh();
    expect([$done->resolution, $done->disposition, $done->resolved_by, $done->external_ref, $done->value_snapshot])->toBe(['REPLACED', 'WRITE_OFF', $resolver->id, 'อว 7301/0123', '199.98']);

    // The old pieces left, the same number came in: the total is back where it was and the new pieces are lendable.
    $a = $asset->fresh();
    expect([$a->qty_total, $a->qty_on_loan, $a->qty_unavailable, $a->available(), $a->status])->toBe(['10.000000', '1.000000', '0.000000', '9.000000', 'ACTIVE']);

    expect(incidentMovementTypes($incident))->toBe([$kind === 'LOST' ? 'MARK_LOST' : 'RETURN_DAMAGED', 'WRITE_OFF', 'REPLACE_IN']);
    $out = LoanMovement::where('incident_id', $incident->id)->where('movement_type', 'WRITE_OFF')->sole();
    $in = LoanMovement::where('incident_id', $incident->id)->where('movement_type', 'REPLACE_IN')->sole();
    expect([$out->qty, $out->qty_total_after, $out->qty_unavailable_after, $out->created_by, $out->approved_by, $out->external_ref])
        ->toBe(['2.000000', '8.000000', '0.000000', $resolver->id, null, 'อว 7301/0123']);
    expect([$in->qty, $in->qty_total_after, $in->qty_on_loan_after, $in->qty_unavailable_after, $in->external_ref, $in->loan_id, $in->loan_line_id])
        ->toBe(['2.000000', '10.000000', '1.000000', '0.000000', 'อว 7301/0123', $loan->id, $incident->loan_line_id]);
    // BR-L17: the person who took the replacement in and the person who approved it are both on the row, and differ.
    expect([$in->created_by, $in->approved_by])->toBe([$receiver->id, $resolver->id]);
    expect($in->prev_row_hash)->toBe($out->row_hash);

    $replaceAudit = AuditLog::where('action', 'LOAN_REPLACE_IN')->where('entity_type', LoanAsset::class)->where('entity_id', $asset->id)->sole();
    expect($replaceAudit->user_id)->toBe($resolver->id);
    expect($replaceAudit->new_value)->toMatchArray([
        'qty_total' => '10.000000', 'qty' => '2.000000', 'incident_id' => $incident->id, 'received_by' => $receiver->id, 'approved_by' => $resolver->id, 'external_ref' => 'อว 7301/0123',
    ]);
    expect(AuditLog::where('action', 'LOAN_WRITE_OFF')->where('entity_type', LoanAsset::class)->where('entity_id', $asset->id)->sole()->new_value['retired_empty'])->toBeFalse();
    expect(loanIncidentAudit('LOAN_INCIDENT_RESOLVE', $incident)[0]->new_value)->toMatchArray(['resolution' => 'REPLACED', 'disposition' => 'WRITE_OFF', 'replacement_received_by' => $receiver->id]);

    // A replacement is a write-off too: ADMIN is told.
    expect(Notification::where('user_id', $admin->id)->where('type', LoanNotifier::TYPE_WRITE_OFF)->count())->toBe(1);

    expect($lab->id)->toBe($in->lab_id);
    assertLoanChainIntact($a);
    $this->artisan('loans:verify')->assertExitCode(0);
})->with(['DAMAGED', 'LOST']);

test('REPLACED needs the letter, the reference, the note and a valid receiver: not the resolver, not the borrower, a counter person of this branch — and a refused receiver undoes the write-off', function () {
    [$lab, $asset, , $recorder, $resolver, $receiver] = incidentScene(10);
    // The borrower works the counter of this branch too, so only "the borrower" rule stops them as receiver.
    $borrower = scientistUser(['lab_id' => $lab->id, 'person_type' => 'STAFF', 'profile_completed_at' => now()]);
    $loan = handedOutLoan($borrower, $asset, 2);
    $incident = recordLoanIncident($loan, $recorder, 'LOST', 1);
    $service = app(LoanIncidentService::class);
    $other = makeLab();

    $before = loanIncidentState($loan);
    loanResolvePost($this, $resolver, $incident, loanResolveData('REPLACED', ['receiver_id' => $receiver->id]))->assertSessionHasErrors(['incident' => __('loans.error.incident_doc_required')]);
    attachLoanIncidentFile($incident, $recorder);
    loanResolvePost($this, $resolver, $incident, loanResolveData('REPLACED', ['receiver_id' => $receiver->id, 'external_ref' => null]))->assertSessionHasErrors(['incident' => __('loans.error.incident_ref_required')]);
    loanResolvePost($this, $resolver, $incident, loanResolveData('REPLACED', ['receiver_id' => $receiver->id, 'resolution_note' => null]))->assertSessionHasErrors('resolution_note');
    loanResolvePost($this, $resolver, $incident, loanResolveData('REPLACED', ['receiver_id' => $receiver->id, 'disposition' => 'RETURN_TO_SERVICE', 'condition_after' => 'GOOD']))
        ->assertSessionHasErrors(['incident' => __('loans.error.incident_lost_no_return')]);

    // No receiver at all: the Form Request; a caller that skips it: the service.
    loanResolvePost($this, $resolver, $incident, loanResolveData('REPLACED'))->assertSessionHasErrors(['receiver_id' => __('loans.error.replace_receiver_invalid')]);
    loanResolvePost($this, $resolver, $incident, loanResolveData('REPLACED', ['receiver_id' => 'abc']))->assertSessionHasErrors('receiver_id');
    expect(fn () => $service->resolve($resolver->fresh(), $incident, loanResolveData('REPLACED')))->toThrow(LoanDomainException::class, __('loans.error.replace_receiver_invalid'));

    $bad = [
        'the resolver themself' => $resolver,
        'the borrower (a counter person of the branch)' => $borrower,
        'a scientist of another branch' => scientistUser(['lab_id' => $other->id]),
        'a warehouse manager of another branch' => auditorUser(['lab_id' => $other->id]),
        'a scientist with no branch' => scientistUser(['lab_id' => null]),
        'an ADMIN-only account of this branch' => adminUser(['lab_id' => $lab->id]),
        'a STAFF member of this branch' => staffUser(['lab_id' => $lab->id]),
        'an inactive scientist of this branch' => scientistUser(['lab_id' => $lab->id, 'is_active' => false]),
    ];
    foreach ($bad as $case => $person) {
        loanResolvePost($this, $resolver, $incident, loanResolveData('REPLACED', ['receiver_id' => $person->id]))
            ->assertSessionHasErrors(['incident' => __('loans.error.replace_receiver_invalid')]);
        expect(fn () => $service->resolve($resolver->fresh(), $incident, loanResolveData('REPLACED', ['receiver_id' => $person->id])))
            ->toThrow(LoanDomainException::class, __('loans.error.replace_receiver_invalid'));
        // The WRITE_OFF that was written just before the receiver was refused is gone again.
        expect(loanIncidentState($loan))->toBe($before, $case);
    }
    loanResolvePost($this, $resolver, $incident, loanResolveData('REPLACED', ['receiver_id' => 999999999]))->assertSessionHasErrors(['incident' => __('loans.error.replace_receiver_invalid')]);
    expect(loanIncidentState($loan))->toBe($before);
    expect(LoanMovement::where('incident_id', $incident->id)->whereIn('movement_type', ['WRITE_OFF', 'REPLACE_IN'])->count())->toBe(0);

    // The ledger's own REPLACE_IN refuses the same people when called directly (receiver = approver, receiver = borrower).
    $incident->setRelation('line', $loan->lines()->firstOrFail());
    foreach ([$resolver, $borrower] as $person) {
        expect(fn () => loanService()->replaceIn($person->fresh(), $resolver->fresh(), $asset->fresh(), $loan->fresh(), $incident, 'อว 7301/0123', 'ผู้บริหารพิจารณาตามบันทึกข้อความแล้ว'))
            ->toThrow(LoanDomainException::class, __('loans.error.replace_receiver_invalid'));
    }
    expect(loanIncidentState($loan))->toBe($before);

    // The person who recorded the incident at the counter MAY be the one who takes the replacement in.
    loanResolvePost($this, $resolver, $incident, loanResolveData('REPLACED', ['receiver_id' => $recorder->id]))->assertSessionHasNoErrors();
    $in = LoanMovement::where('incident_id', $incident->id)->where('movement_type', 'REPLACE_IN')->sole();
    expect([$in->created_by, $in->approved_by])->toBe([$recorder->id, $resolver->id]);
    $this->artisan('loans:verify')->assertExitCode(0);
});

test('QA fix: an asset cannot be RETIRED while it has unavailable pieces / a PENDING incident (service and form); once every result is recorded it can', function () {
    [$lab, $asset, $borrower, $recorder, $resolver] = incidentScene(5);
    $manager = labManagerUser(['lab_id' => $lab->id]);
    $loan = handedOutLoan($borrower, $asset, 2);
    $lost = recordLoanIncident($loan, $recorder, 'LOST', 1);
    $damaged = recordLoanIncident($loan, $recorder, 'DAMAGED', 1);
    attachLoanIncidentFile($lost, $recorder);
    $note = 'เลิกให้ยืมอุปกรณ์รุ่นนี้แล้ว';
    // Nothing is out any more (qty_on_loan = 0) — the old check alone let this through.
    expect($asset->fresh()->qty_on_loan)->toBe('0.000000');
    $before = loanIncidentState($loan);

    expect(fn () => loanService()->retire($manager->fresh(), $asset->fresh(), $note, $resolver->id))
        ->toThrow(LoanDomainException::class, __('loans.error.retire_while_incident'));
    $this->actingAs($manager)->post(route('loans.assets.retire', $asset), ['note' => $note, 'approved_by' => $resolver->id])
        ->assertSessionHasErrors(['retire' => __('loans.error.retire_while_incident')]);
    expect(loanIncidentState($loan))->toBe($before);
    expect($asset->fresh()->status)->toBe('ACTIVE');

    // One case still open is enough to refuse.
    loanResolvePost($this, $resolver, $lost, loanResolveData('COMPENSATED'))->assertSessionHasNoErrors();
    expect(fn () => loanService()->retire($manager->fresh(), $asset->fresh(), $note, $resolver->id))
        ->toThrow(LoanDomainException::class, __('loans.error.retire_while_incident'));

    // Every result recorded: nothing out, nothing unavailable -> the retirement goes through.
    loanResolvePost($this, $resolver, $damaged, ['resolution' => 'REPAIRED', 'condition_after' => 'GOOD', 'resolution_note' => 'ซ่อมแล้วใช้งานได้ตามปกติ'])->assertSessionHasNoErrors();
    loanService()->retire($manager->fresh(), $asset->fresh(), $note, $resolver->id);
    $a = $asset->fresh();
    expect([$a->qty_total, $a->qty_on_loan, $a->qty_unavailable, $a->status])->toBe(['4.000000', '0.000000', '0.000000', 'RETIRED']);
    assertLoanChainIntact($a);
    $this->artisan('loans:verify')->assertExitCode(0);
});

test('an asset that is RETIRED while an incident waits (data from before the QA fix, set directly) cannot take a replacement in; its incident can still be written off or repaired', function () {
    [$lab, $asset, $borrower, $recorder, $resolver, $receiver] = incidentScene(5);
    $loan = handedOutLoan($borrower, $asset, 2);
    $lost = recordLoanIncident($loan, $recorder, 'LOST', 1);
    $damaged = recordLoanIncident($loan, $recorder, 'DAMAGED', 1);
    attachLoanIncidentFile($lost, $recorder);
    // retire() refuses this now; the guard in replaceIn() stays for rows retired before the fix.
    DB::table('loan_assets')->where('id', $asset->id)->update(['status' => 'RETIRED']);
    expect($asset->fresh()->status)->toBe('RETIRED');
    $service = app(LoanIncidentService::class);

    $before = loanIncidentState($loan);
    loanResolvePost($this, $resolver, $lost, loanResolveData('REPLACED', ['receiver_id' => $receiver->id]))->assertSessionHasErrors(['incident' => __('loans.error.asset_retired')]);
    expect(fn () => $service->resolve($resolver->fresh(), $lost, loanResolveData('REPLACED', ['receiver_id' => $receiver->id])))->toThrow(LoanDomainException::class, __('loans.error.asset_retired'));
    expect(loanIncidentState($loan))->toBe($before);

    loanResolvePost($this, $resolver, $lost, loanResolveData('COMPENSATED'))->assertSessionHasNoErrors();
    loanResolvePost($this, $resolver, $damaged, ['resolution' => 'REPAIRED', 'condition_after' => 'GOOD', 'resolution_note' => 'ซ่อมแล้วเก็บเข้าคลังที่ปลดระวาง'])->assertSessionHasNoErrors();

    $a = $asset->fresh();
    expect([$a->qty_total, $a->qty_on_loan, $a->qty_unavailable, $a->status])->toBe(['4.000000', '0.000000', '0.000000', 'RETIRED']);
    expect($loan->fresh()->status)->toBe('CLOSED');
    assertLoanChainIntact($a);
    $this->artisan('loans:verify')->assertExitCode(0);
});

// --- UNIT assets ---------------------------------------------------------------------------------------------------

test('[CONFIRM] a UNIT piece that is written off leaves nothing to lend: the asset becomes RETIRED by itself (no RETIRE movement), and the loan closes', function (string $kind, string $resolution) {
    [$lab, , $borrower, $recorder, $resolver] = incidentScene(3);
    $unit = loanExtraAsset($lab, 1, null, 'UNIT');
    setLoanAssetValue($unit, '12000.00');
    $loan = handedOutLoan($borrower, $unit, 1);
    $incident = recordLoanIncident($loan, $recorder, $kind, 1);
    attachLoanIncidentFile($incident, $recorder);
    $u = $unit->fresh();
    expect([$u->qty_total, $u->qty_on_loan, $u->qty_unavailable, $u->available(), $u->status])->toBe(['1.000000', '0.000000', '1.000000', '0.000000', 'ACTIVE']);

    loanResolvePost($this, $resolver, $incident, loanResolveData($resolution, ['disposition' => 'WRITE_OFF']))->assertSessionHasNoErrors();

    $u = $unit->fresh();
    expect([$u->qty_total, $u->qty_on_loan, $u->qty_unavailable, $u->available(), $u->status])->toBe(['0.000000', '0.000000', '0.000000', '0.000000', 'RETIRED']);
    expect(LoanMovement::where('loan_asset_id', $unit->id)->orderBy('id')->pluck('movement_type')->all())
        ->toBe(['REGISTER', 'HANDOUT', $kind === 'LOST' ? 'MARK_LOST' : 'RETURN_DAMAGED', 'WRITE_OFF']);
    $audit = AuditLog::where('action', 'LOAN_WRITE_OFF')->where('entity_type', LoanAsset::class)->where('entity_id', $unit->id)->sole();
    expect($audit->new_value)->toMatchArray(['qty_total' => '0.000000', 'retired_empty' => true]);
    expect(AuditLog::where('action', 'LOAN_ASSET_RETIRE')->where('entity_type', LoanAsset::class)->where('entity_id', $unit->id)->count())->toBe(0);
    expect($incident->fresh()->value_snapshot)->toBe('12000.00');

    // BR-L12: it was the only thing on the document and its only incident.
    $closed = $loan->fresh();
    expect($closed->status)->toBe('CLOSED')->and($closed->closed_at)->not->toBeNull();
    expect(loanIncidentAudit('LOAN_INCIDENT_RESOLVE', $incident)[0]->new_value['loan_closed'])->toBeTrue();

    // A retired unit can never be asked for or handed out again.
    expect(fn () => draftLoan($borrower, $unit, 1))->toThrow(LoanDomainException::class);
    assertLoanChainIntact($u);
    $this->artisan('loans:verify')->assertExitCode(0);
})->with([
    'LOST, WAIVED' => ['LOST', 'WAIVED'],
    'LOST, COMPENSATED' => ['LOST', 'COMPENSATED'],
    'DAMAGED, COMPENSATED' => ['DAMAGED', 'COMPENSATED'],
    'DAMAGED, WAIVED' => ['DAMAGED', 'WAIVED'],
]);

test('[CONFIRM] a POOL whose last pieces are written off is RETIRED too, which frees the branch + item pair for a new pool', function () {
    [$lab, $asset, $borrower, $recorder, $resolver] = incidentScene(2);
    $manager = labManagerUser(['lab_id' => $lab->id]);
    $loan = handedOutLoan($borrower, $asset, 2);
    $first = recordLoanIncident($loan, $recorder, 'LOST', 1);
    $second = recordLoanIncident($loan, $recorder, 'DAMAGED', 1);
    attachLoanIncidentFile($first, $recorder);
    attachLoanIncidentFile($second, $recorder);

    // One piece written off: one is left in the register (unavailable), the pool stays ACTIVE.
    loanResolvePost($this, $resolver, $first, loanResolveData('WAIVED'))->assertSessionHasNoErrors();
    $a = $asset->fresh();
    expect([$a->qty_total, $a->qty_unavailable, $a->status])->toBe(['1.000000', '1.000000', 'ACTIVE']);
    expect(fn () => registerLoanAsset($manager->fresh(), $lab, 'POOL', 3, $asset->item))->toThrow(LoanDomainException::class, __('loans.error.pool_exists'));

    // The last one: the total reaches zero.
    loanResolvePost($this, $resolver, $second, loanResolveData('COMPENSATED', ['disposition' => 'WRITE_OFF']))->assertSessionHasNoErrors();
    $a = $asset->fresh();
    expect([$a->qty_total, $a->qty_on_loan, $a->qty_unavailable, $a->status])->toBe(['0.000000', '0.000000', '0.000000', 'RETIRED']);
    expect(AuditLog::where('action', 'LOAN_WRITE_OFF')->where('entity_type', LoanAsset::class)->where('entity_id', $asset->id)->orderBy('id')->get()->map(fn ($r) => $r->new_value['retired_empty'])->all())->toBe([false, true]);
    expect($loan->fresh()->status)->toBe('CLOSED');

    $new = registerLoanAsset($manager->fresh(), $lab, 'POOL', 3, $asset->item);
    expect($new->status)->toBe('ACTIVE')->and($new->id)->not->toBe($asset->id);
    $this->artisan('loans:verify')->assertExitCode(0);
});

test('a UNIT piece that is repaired, kept in service or replaced stays ACTIVE and can be lent again', function (string $kind, array $payload, bool $needsDoc) {
    [$lab, , $borrower, $recorder, $resolver, $receiver] = incidentScene(3);
    $unit = loanExtraAsset($lab, 1, null, 'UNIT');
    $loan = handedOutLoan($borrower, $unit, 1);
    $incident = recordLoanIncident($loan, $recorder, $kind, 1);
    if ($needsDoc) {
        attachLoanIncidentFile($incident, $recorder);
    }
    if (($payload['resolution'] ?? '') === 'REPLACED') {
        $payload['receiver_id'] = $receiver->id;
    }

    loanResolvePost($this, $resolver, $incident, $payload)->assertSessionHasNoErrors();

    $u = $unit->fresh();
    expect([$u->qty_total, $u->qty_on_loan, $u->qty_unavailable, $u->available(), $u->status])->toBe(['1.000000', '0.000000', '0.000000', '1.000000', 'ACTIVE']);
    expect($loan->fresh()->status)->toBe('CLOSED');
    $this->artisan('loans:verify')->assertExitCode(0);

    $again = handedOutLoan($borrower, $unit, 1);
    expect($again->status)->toBe('ON_LOAN')->and($unit->fresh()->qty_on_loan)->toBe('1.000000');
    assertLoanChainIntact($unit->fresh());
})->with([
    'DAMAGED, REPAIRED' => ['DAMAGED', ['resolution' => 'REPAIRED', 'condition_after' => 'GOOD', 'resolution_note' => 'ซ่อมแล้วใช้งานได้ตามปกติ'], false],
    'DAMAGED, WAIVED, kept in service' => ['DAMAGED', ['resolution' => 'WAIVED', 'disposition' => 'RETURN_TO_SERVICE', 'condition_after' => 'FAIR', 'external_ref' => 'อว 7301/0456', 'resolution_note' => 'ยกเว้นการชดใช้ ของยังใช้งานได้'], false],
    'DAMAGED, REPLACED' => ['DAMAGED', ['resolution' => 'REPLACED', 'external_ref' => 'อว 7301/0456', 'resolution_note' => 'ได้รับเครื่องทดแทนรุ่นเดียวกัน'], true],
    'LOST, REPLACED' => ['LOST', ['resolution' => 'REPLACED', 'external_ref' => 'อว 7301/0456', 'resolution_note' => 'ได้รับเครื่องทดแทนรุ่นเดียวกัน'], true],
]);

// --- BR-L12: closing the document ----------------------------------------------------------------------------------

test('BR-L12: with everything back the loan still cannot close while an incident is PENDING; it closes with the LAST result, not before', function () {
    [, $asset, $borrower, $recorder, $resolver] = incidentScene(10);
    $loan = handedOutLoan($borrower, $asset, 3);
    $service = app(LoanCirculationService::class);

    // One usable, one damaged, one lost: nothing is with the borrower.
    $out = $service->receiveReturn($recorder->fresh(), $loan, [1 => ['qty' => '1', 'condition' => 'GOOD', 'qty_damaged' => '1', 'qty_lost' => '1', 'incident_note' => INCIDENT_NOTE]]);
    expect($out->status)->toBe('PARTIALLY_RETURNED')->and($out->closed_at)->toBeNull();
    expect($loan->lines()->firstOrFail()->outstanding())->toBe('0.000000');
    [$damaged, $lost] = LoanIncident::where('loan_id', $loan->id)->orderBy('id')->get()->all();
    attachLoanIncidentFile($lost, $recorder);

    // There is no way to close it from the counter: nothing is left to take back.
    $before = loanIncidentState($loan);
    loanReturnPost($this, $recorder, $loan, [1 => ['qty' => '1', 'condition' => 'GOOD']])->assertSessionHasErrors(['return' => __('loans.error.return_too_much')]);
    loanReturnPost($this, $recorder, $loan, [1 => ['qty' => '', 'condition' => 'GOOD']])->assertSessionHasErrors(['return' => __('loans.error.return_nothing')]);
    expect(loanIncidentState($loan))->toBe($before);
    // The page says why it is still open.
    $this->actingAs($recorder)->get(route('loans.show', $loan))->assertOk()->assertSee(__('loans.incident_pending_loan_note'))->assertSee(__('loans.loan_status_partially_returned'));

    // The first result: one incident still waits.
    loanResolvePost($this, $resolver, $damaged, ['resolution' => 'REPAIRED', 'condition_after' => 'GOOD', 'resolution_note' => 'ซ่อมแล้วใช้งานได้ตามปกติ'])->assertSessionHasNoErrors();
    $fresh = $loan->fresh();
    expect($fresh->status)->toBe('PARTIALLY_RETURNED')->and($fresh->closed_at)->toBeNull();
    expect(loanIncidentAudit('LOAN_INCIDENT_RESOLVE', $damaged)[0]->new_value['loan_closed'])->toBeFalse();
    expect(app(LoanIncidentService::class)->hasPending($fresh))->toBeTrue();

    // The last result closes the document in the same action.
    loanResolvePost($this, $resolver, $lost, loanResolveData('WAIVED'))->assertSessionHasNoErrors();
    $closed = $loan->fresh();
    expect($closed->status)->toBe('CLOSED')->and($closed->closed_at)->not->toBeNull();
    expect(loanIncidentAudit('LOAN_INCIDENT_RESOLVE', $lost)[0]->new_value['loan_closed'])->toBeTrue();
    expect(app(LoanIncidentService::class)->hasPending($closed))->toBeFalse();
    $this->actingAs($recorder)->get(route('loans.show', $loan))->assertOk()->assertDontSee(__('loans.incident_pending_loan_note'))->assertSee(__('loans.loan_status_closed'));

    // Closed: nothing more can be taken back.
    loanReturnPost($this, $recorder, $loan, [1 => ['qty' => '1', 'condition' => 'GOOD']])->assertForbidden();
    $this->artisan('loans:verify')->assertExitCode(0);
});

test('BR-L12: a result recorded while pieces are still out does not close the loan; the last ordinary return does', function () {
    [, $asset, $borrower, $recorder, $resolver] = incidentScene(10);
    $loan = handedOutLoan($borrower, $asset, 3);
    $incident = recordLoanIncident($loan, $recorder, 'DAMAGED', 1);

    loanResolvePost($this, $resolver, $incident, ['resolution' => 'REPAIRED', 'condition_after' => 'GOOD', 'resolution_note' => 'ซ่อมแล้วใช้งานได้ตามปกติ'])->assertSessionHasNoErrors();
    expect($loan->fresh()->status)->toBe('PARTIALLY_RETURNED')->and($loan->fresh()->closed_at)->toBeNull();

    loanReturnPost($this, $recorder, $loan, [1 => ['qty' => '1', 'condition' => 'GOOD']])->assertSessionHas('status', __('loans.returned_partial'));
    expect($loan->fresh()->status)->toBe('PARTIALLY_RETURNED');
    loanReturnPost($this, $recorder, $loan, [1 => ['qty' => '1', 'condition' => 'GOOD']])->assertSessionHas('status', __('loans.returned_closed'));
    $closed = $loan->fresh();
    expect($closed->status)->toBe('CLOSED')->and($closed->closed_at)->not->toBeNull();
    expect(loanAudit('LOAN_RETURN', $loan)->last()->new_value)->toMatchArray(['closed' => true, 'pending_incident' => false]);
    $this->artisan('loans:verify')->assertExitCode(0);
});

test('BR-L12: an incident on ANOTHER loan of the same borrower and asset does not hold this loan open', function () {
    [, $asset, $borrower, $recorder] = incidentScene(10);
    $withIncident = handedOutLoan($borrower, $asset, 1);
    $clean = handedOutLoan($borrower, $asset, 1);
    recordLoanIncident($withIncident, $recorder, 'LOST', 1);

    loanReturnPost($this, $recorder, $clean, [1 => ['qty' => '1', 'condition' => 'GOOD']])->assertSessionHas('status', __('loans.returned_closed'));
    expect($clean->fresh()->status)->toBe('CLOSED')->and($withIncident->fresh()->status)->toBe('PARTIALLY_RETURNED');
});

// --- a recorded result is final ------------------------------------------------------------------------------------

test('a recorded result is final: a second result is refused on the page and in the service, whatever it is', function () {
    [, $asset, $borrower, $recorder, $resolver, $receiver] = incidentScene(10);
    $second = auditorUser(['lab_id' => $asset->lab_id]);
    $loan = handedOutLoan($borrower, $asset, 3);
    $incident = recordLoanIncident($loan, $recorder, 'DAMAGED', 1);
    attachLoanIncidentFile($incident, $recorder);
    $service = app(LoanIncidentService::class);

    $service->resolve($resolver->fresh(), $incident, ['resolution' => 'REPAIRED', 'condition_after' => 'GOOD', 'resolution_note' => 'ซ่อมแล้วใช้งานได้ตามปกติ']);
    $before = loanIncidentState($loan);

    $attempts = [
        ['resolution' => 'REPAIRED', 'condition_after' => 'FAIR', 'resolution_note' => 'แก้ไขผลการซ่อมย้อนหลัง'],
        loanResolveData('WAIVED', ['disposition' => 'WRITE_OFF']),
        loanResolveData('REPLACED', ['receiver_id' => $receiver->id]),
    ];
    foreach ($attempts as $payload) {
        foreach ([$resolver, $second] as $person) {
            loanResolvePost($this, $person, $incident, $payload)->assertForbidden();
            expect(fn () => $service->resolve($person->fresh(), $incident->fresh(), $payload))->toThrow(LoanDomainException::class, __('loans.error.incident_not_pending'));
        }
    }
    // The ledger's own entry points refuse a decided incident too.
    $decided = $incident->fresh();
    $decided->setRelation('line', $loan->lines()->firstOrFail());
    expect(fn () => loanService()->repairDone($second->fresh(), $asset->fresh(), $loan->fresh(), $decided, 'GOOD', 'ซ่อมแล้วใช้งานได้ตามปกติ'))->toThrow(LoanDomainException::class, __('loans.error.incident_not_pending'));
    expect(fn () => loanService()->writeOff($second->fresh(), $asset->fresh(), $loan->fresh(), $decided, 'อว 7301/0123', 'ผู้บริหารพิจารณาตามบันทึกข้อความแล้ว'))->toThrow(LoanDomainException::class, __('loans.error.incident_not_pending'));
    expect(fn () => loanService()->replaceIn($receiver->fresh(), $second->fresh(), $asset->fresh(), $loan->fresh(), $decided, 'อว 7301/0123', 'ผู้บริหารพิจารณาตามบันทึกข้อความแล้ว'))->toThrow(LoanDomainException::class, __('loans.error.incident_not_pending'));

    expect(loanIncidentState($loan))->toBe($before);
    expect(loanIncidentAudit('LOAN_INCIDENT_RESOLVE', $incident))->toHaveCount(1);
});

test('the database trigger refuses ANY update of a decided incident (SQLSTATE 45000), and the model refuses it too; a PENDING row can still be written', function (array $change) {
    [, $asset, $borrower, $recorder, $resolver] = incidentScene(10);
    $loan = handedOutLoan($borrower, $asset, 3);
    $decided = recordLoanIncident($loan, $recorder, 'DAMAGED', 1);
    $pending = recordLoanIncident($loan, $recorder, 'LOST', 1);
    app(LoanIncidentService::class)->resolve($resolver->fresh(), $decided, ['resolution' => 'REPAIRED', 'condition_after' => 'GOOD', 'resolution_note' => 'ซ่อมแล้วใช้งานได้ตามปกติ']);
    $row = fn () => (array) DB::table('loan_incidents')->where('id', $decided->id)->first();
    $before = $row();

    try {
        DB::table('loan_incidents')->where('id', $decided->id)->update($change);
        expect(false)->toBeTrue('the UPDATE of a decided incident must be rejected');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('45000');
        expect($e->getMessage())->toContain('a resolved loan incident is final');
    }
    expect($row())->toBe($before);

    // The model's own guard (before the query is even sent).
    $model = LoanIncident::findOrFail($decided->id);
    expect(fn () => $model->forceFill($change)->save())->toThrow(LogicException::class, 'a resolved loan incident is final');
    expect($row())->toBe($before);

    // The trigger is about decided rows only: the same change on the PENDING one is not stopped by it.
    $affected = DB::table('loan_incidents')->where('id', $pending->id)->update(['description' => 'แก้คำอธิบายระหว่างรอผล (ยังไม่ตัดสิน)']);
    expect($affected)->toBe(1);
})->with([
    'the result' => [['resolution' => 'WAIVED']],
    'back to PENDING' => [['resolution' => 'PENDING', 'disposition' => null, 'resolved_by' => null, 'resolved_at' => null]],
    'the disposition' => [['disposition' => 'WRITE_OFF']],
    'the note' => [['resolution_note' => 'แก้ไขหมายเหตุย้อนหลัง']],
    'the reference' => [['external_ref' => 'อว 9999/9999']],
    'the value' => [['value_snapshot' => '1.00']],
    'the quantity' => [['qty' => '5.000000']],
    'the kind' => [['kind' => 'LOST']],
    'the description' => [['description' => 'แก้คำอธิบายย้อนหลัง']],
]);

test('the database CHECKs refuse an incident row the service would never write, and an incident with movements cannot be deleted', function () {
    [, $asset, $borrower, $recorder, $resolver] = incidentScene(10);
    $loan = handedOutLoan($borrower, $asset, 4);
    $damaged = recordLoanIncident($loan, $recorder, 'DAMAGED', 1);
    $lost = recordLoanIncident($loan, $recorder, 'LOST', 1);
    $resolved = ['resolved_by' => $resolver->id, 'resolved_at' => now()];

    $refused = function (int $id, array $change, string $constraint): void {
        $before = (array) DB::table('loan_incidents')->where('id', $id)->first();
        try {
            DB::table('loan_incidents')->where('id', $id)->update($change);
            expect(false)->toBeTrue("{$constraint} must refuse ".json_encode($change));
        } catch (QueryException $e) {
            expect($e->getMessage())->toContain($constraint);
        }
        expect((array) DB::table('loan_incidents')->where('id', $id)->first())->toBe($before);
    };

    // chk_li_state: a quantity above zero; PENDING has no result columns; a result has all of them and another person.
    $refused($damaged->id, ['qty' => 0], 'chk_li_state');
    $refused($damaged->id, ['disposition' => 'WRITE_OFF'], 'chk_li_state');
    $refused($damaged->id, ['resolved_by' => $resolver->id], 'chk_li_state');
    $refused($damaged->id, ['resolution' => 'WAIVED', 'disposition' => 'WRITE_OFF'], 'chk_li_state');
    $refused($damaged->id, ['resolution' => 'WAIVED'] + $resolved, 'chk_li_state');
    // The person who recorded the incident can never be the one on the result (BR-L11 point 2), even written by hand.
    $refused($damaged->id, ['resolution' => 'WAIVED', 'disposition' => 'WRITE_OFF', 'resolved_by' => $recorder->id, 'resolved_at' => now()], 'chk_li_state');

    // chk_li_outcome: which result goes with which kind.
    $refused($lost->id, ['resolution' => 'REPAIRED', 'disposition' => 'RETURN_TO_SERVICE'] + $resolved, 'chk_li_outcome');
    $refused($lost->id, ['resolution' => 'WAIVED', 'disposition' => 'RETURN_TO_SERVICE'] + $resolved, 'chk_li_outcome');
    $refused($damaged->id, ['resolution' => 'REPAIRED', 'disposition' => 'WRITE_OFF'] + $resolved, 'chk_li_outcome');
    $refused($damaged->id, ['resolution' => 'REPLACED', 'disposition' => 'RETURN_TO_SERVICE'] + $resolved, 'chk_li_outcome');

    // The ENUMs: no other kind / result exists.
    foreach ([['kind' => 'STOLEN'], ['resolution' => 'FORGIVEN']] as $change) {
        try {
            DB::table('loan_incidents')->where('id', $damaged->id)->update($change);
            expect(false)->toBeTrue('an unknown ENUM value must be refused');
        } catch (QueryException $e) {
            expect($e->getMessage())->toMatch('/truncated|Incorrect|chk_li/i');
        }
    }

    // loan_movements.incident_id -> loan_incidents (RESTRICT): the incident's ledger rows can never be orphaned.
    try {
        DB::table('loan_incidents')->where('id', $damaged->id)->delete();
        expect(false)->toBeTrue('deleting an incident that has movements must be refused');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('fk_lm_incident');
    }
    expect(LoanIncident::whereKey([$damaged->id, $lost->id])->where('resolution', 'PENDING')->count())->toBe(2);
});

test('only the free-text description of an incident is mass-assignable; the route key is the ULID', function () {
    $incident = new LoanIncident([
        'description' => 'ข้อความจากฟอร์ม', 'loan_id' => 1, 'loan_line_id' => 1, 'kind' => 'LOST', 'qty' => 99, 'resolution' => 'WAIVED',
        'disposition' => 'WRITE_OFF', 'value_snapshot' => 1, 'external_ref' => 'x', 'recorded_by' => 1, 'resolved_by' => 2, 'resolved_at' => now(), 'resolution_note' => 'x',
    ]);

    expect($incident->getAttributes())->toBe(['description' => 'ข้อความจากฟอร์ม']);
    expect($incident->getRouteKeyName())->toBe('ulid');
});

// --- overdue while an incident waits -------------------------------------------------------------------------------

test('[CONFIRM] a loan whose pieces are all back (or written down) but whose incident still waits is NOT overdue, and does not block the borrower (BR-L08)', function () {
    [, $asset, $borrower, $recorder] = incidentScene(10);
    $late = handedOutLoan($borrower, $asset, 2);
    $this->travelTo($late->due_date->copy()->addDays(9)->setTime(10, 0));

    // Nine days late with everything out: overdue, and the borrower is blocked.
    expect($late->fresh()->isOverdue())->toBeTrue()->and($late->fresh()->daysOverdue())->toBe(9);
    expect(Loan::query()->overdue()->whereKey($late->id)->exists())->toBeTrue();
    expect(app(LoanService::class)->blockingOverdueLoans($borrower->id))->toBe([$late->doc_no]);
    expect(fn () => draftLoan($borrower, $asset, 1))->toThrow(LoanDomainException::class);

    // One piece is written down as lost, one is still out: still overdue, still blocked.
    recordLoanIncident($late, $recorder, 'LOST', 1);
    expect($late->fresh()->isOverdue())->toBeTrue();
    expect(Loan::query()->overdue(7)->whereKey($late->id)->exists())->toBeTrue();
    expect(app(LoanService::class)->blockingOverdueLoans($borrower->id))->toBe([$late->doc_no]);

    // The last piece comes back damaged: nothing is with the borrower; the document waits for the branch, not for them.
    recordLoanIncident($late, $recorder, 'DAMAGED', 1);
    $fresh = $late->fresh();
    expect($fresh->status)->toBe('PARTIALLY_RETURNED')->and($fresh->hasOutstanding())->toBeFalse();
    expect($fresh->isOverdue())->toBeFalse()->and($fresh->daysOverdue())->toBe(0);
    expect(Loan::query()->overdue()->whereKey($late->id)->exists())->toBeFalse();
    expect(Loan::query()->overdue(7)->whereKey($late->id)->exists())->toBeFalse();
    expect(app(LoanService::class)->blockingOverdueLoans($borrower->id))->toBe([]);

    // The borrower can ask for something new, and the lists do not flag the old document as late.
    $this->actingAs($borrower)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], ['action' => 'submit']))->assertSessionHasNoErrors();
    expect(Loan::where('borrower_id', $borrower->id)->where('id', '!=', $late->id)->sole()->status)->toBe('SUBMITTED');
    $this->actingAs($borrower)->get(route('loans.mine', ['overdue' => '1']))->assertOk()->assertDontSee($late->doc_no);
    $this->actingAs($borrower)->get(route('loans.mine'))->assertOk()->assertSee($late->doc_no);
});

test('after the last result closed the loan, a renewal can no longer be asked for', function () {
    [, $asset, $borrower, $recorder, $resolver] = incidentScene(10);
    $loan = handedOutLoan($borrower, $asset, 1);
    $incident = recordLoanIncident($loan, $recorder, 'DAMAGED', 1);
    expect($loan->fresh()->status)->toBe('PARTIALLY_RETURNED');

    app(LoanIncidentService::class)->resolve($resolver->fresh(), $incident, ['resolution' => 'REPAIRED', 'condition_after' => 'GOOD', 'resolution_note' => 'ซ่อมแล้วใช้งานได้ตามปกติ']);
    expect($loan->fresh()->status)->toBe('CLOSED');

    $this->actingAs($borrower)->post(route('loans.renewals.store', $loan), ['new_due_date' => $loan->due_date->copy()->addDays(3)->format('Y-m-d')])->assertForbidden();
});
