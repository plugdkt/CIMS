<?php

use App\Domain\Reporting\Services\SelfApprovalReport;
use App\Domain\Requisition\Services\IssueService;
use App\Livewire\Reports\ReportsDashboard;
use App\Models\Requisition;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * approvals.md K (CEO, 2026-11-04): asking for, approving and issuing one's own requisition is allowed, so the control
 * is a report that lists them: requisitions whose approver is the requester, issued by the requester, or issued by
 * the approver. Computed from the tables (no flag column), own branch for managers, on screen under "reports".
 *
 * (Written without being run: see the report to the secretary.)
 */

if (! function_exists('selfIssue')) {
    /** Issues 1 g of the requisition's first line as $issuer, through the real service (writes issue_transactions). */
    function selfIssue(Requisition $requisition, \App\Models\User $issuer): void
    {
        $line = $requisition->items()->firstOrFail();
        $container = stockedContainer($line->item_id, '50.000000', $issuer);

        app(IssueService::class)->issue(
            $line,
            $container,
            '1',
            Unit::where('code', 'g')->firstOrFail(),
            $issuer,
            $requisition->requester,
            hash('sha256', 'self-report'),
        );
    }
}

if (! function_exists('selfReportFixture')) {
    /** @return array<string, mixed> one branch with every case the report must (or must not) list */
    function selfReportFixture(): array
    {
        $lab = makeLab();
        $otherLab = makeLab();
        $a = scientistUser(['lab_id' => $lab->id, 'person_type' => 'STAFF']);          // approver (and sometimes requester)
        $third = scientistUser(['lab_id' => $lab->id]);
        $x = staffUser(['lab_id' => $lab->id]);

        $make = fn ($requester, string $docNo, array $o = []) => requisitionWithLines(
            $requester,
            ['CHEMICAL'],
            array_merge(['lab_id' => $lab->id, 'doc_no' => $docNo, 'status' => 'APPROVED'], $o),
        );

        return [
            'lab' => $lab, 'otherLab' => $otherLab, 'a' => $a, 'third' => $third, 'x' => $x,
            // approved by the requester
            'selfApproved' => $make($a, 'REQ-SELF-0001', ['scientist_id' => $a->id]),
            // issued by the requester (approved by someone else)
            'issuedByRequester' => tap($make($x, 'REQ-SELF-0002', ['scientist_id' => $a->id]), fn ($r) => selfIssue($r, $x)),
            // issued by its approver (requester is a third person)
            'issuedByApprover' => tap($make($x, 'REQ-SELF-0003', ['scientist_id' => $a->id]), fn ($r) => selfIssue($r, $a)),
            // clean: approver A, issuer a third person
            'clean' => tap($make($x, 'REQ-SELF-0004', ['scientist_id' => $a->id]), fn ($r) => selfIssue($r, $third)),
            // not approved yet: nothing to flag
            'waiting' => $make($x, 'REQ-SELF-0005', ['status' => 'SUBMITTED']),
            // self-approved but still a DRAFT: never listed
            'draft' => $make($a, 'REQ-SELF-0006', ['status' => 'DRAFT', 'scientist_id' => $a->id]),
            // another branch, self-approved
            'elsewhere' => requisitionWithLines(
                scientistUser(['lab_id' => $otherLab->id, 'person_type' => 'STAFF']),
                ['CHEMICAL'],
                ['lab_id' => $otherLab->id, 'doc_no' => 'REQ-SELF-0009', 'status' => 'APPROVED'],
            ),
        ];
    }
}

test('the report lists exactly the self-approved / self-issued requisitions, with the right flags', function () {
    $f = selfReportFixture();
    $f['elsewhere']->update(['scientist_id' => $f['elsewhere']->requester_id]);

    $rows = (new SelfApprovalReport())->rows($f['lab']->id)->keyBy(fn ($row) => $row['requisition']->doc_no);

    expect($rows->keys()->sort()->values()->all())->toBe(['REQ-SELF-0001', 'REQ-SELF-0002', 'REQ-SELF-0003']);

    expect($rows['REQ-SELF-0001'])->toMatchArray(['approved_by_requester' => true, 'issued_by_requester' => false, 'issued_by_approver' => false]);
    expect($rows['REQ-SELF-0002'])->toMatchArray(['approved_by_requester' => false, 'issued_by_requester' => true, 'issued_by_approver' => false]);
    expect($rows['REQ-SELF-0003'])->toMatchArray(['approved_by_requester' => false, 'issued_by_requester' => false, 'issued_by_approver' => true]);
});

test('a requisition that is both self-approved and self-issued shows both flags', function () {
    $f = selfReportFixture();
    selfIssue($f['selfApproved'], $f['a']);

    $row = (new SelfApprovalReport())->rows($f['lab']->id)->first(fn ($r) => $r['requisition']->doc_no === 'REQ-SELF-0001');

    expect($row)->toMatchArray(['approved_by_requester' => true, 'issued_by_requester' => true, 'issued_by_approver' => true]);
});

test('the branch filter: one branch\'s report never lists another branch\'s rows; no filter lists them all', function () {
    $f = selfReportFixture();
    $f['elsewhere']->update(['scientist_id' => $f['elsewhere']->requester_id]);
    $docs = fn (?int $labId) => (new SelfApprovalReport())->rows($labId)->map(fn ($r) => $r['requisition']->doc_no)->sort()->values()->all();

    expect($docs($f['lab']->id))->not->toContain('REQ-SELF-0009');
    expect($docs($f['otherLab']->id))->toBe(['REQ-SELF-0009']);
    expect($docs(null))->toContain('REQ-SELF-0009')->toContain('REQ-SELF-0001');
});

test('the date filter works on the requisition\'s creation date', function () {
    $f = selfReportFixture();
    $old = $f['selfApproved'];
    $old->forceFill(['created_at' => now()->subDays(40)])->save();
    $docs = fn (?string $from, ?string $to) => (new SelfApprovalReport())->rows($f['lab']->id, $from, $to)->map(fn ($r) => $r['requisition']->doc_no)->all();

    expect($docs(now()->subDays(5)->toDateString(), now()->toDateString()))->not->toContain('REQ-SELF-0001')->toContain('REQ-SELF-0002');
    expect($docs(now()->subDays(60)->toDateString(), now()->subDays(30)->toDateString()))->toBe(['REQ-SELF-0001']);
});

test('on screen: a manager of the branch sees the flagged rows and their flags, and not another branch\'s', function () {
    $f = selfReportFixture();
    $f['elsewhere']->update(['scientist_id' => $f['elsewhere']->requester_id]);
    $manager = auditorUser(['lab_id' => $f['lab']->id]);

    Livewire::actingAs($manager)->test(ReportsDashboard::class)
        ->set('tab', 'self_approval')
        ->assertSee('REQ-SELF-0001')->assertSee('REQ-SELF-0002')->assertSee('REQ-SELF-0003')
        ->assertDontSee('REQ-SELF-0004')->assertDontSee('REQ-SELF-0005')->assertDontSee('REQ-SELF-0006')->assertDontSee('REQ-SELF-0009')
        ->assertSee(__('reports.flag_approved_by_requester'))
        ->assertSee(__('reports.flag_issued_by_requester'))
        ->assertSee(__('reports.flag_issued_by_approver'));
});

test('on screen: ADMIN sees every branch and can narrow to one', function () {
    $f = selfReportFixture();
    $f['elsewhere']->update(['scientist_id' => $f['elsewhere']->requester_id]);

    Livewire::actingAs(adminUser())->test(ReportsDashboard::class)
        ->set('tab', 'self_approval')
        ->assertSee('REQ-SELF-0001')->assertSee('REQ-SELF-0009')
        ->set('labId', $f['otherLab']->id)
        ->assertSee('REQ-SELF-0009')->assertDontSee('REQ-SELF-0001');
});

test('the report is behind report.view: a scientist, a plain requester and a user with no card cannot open it', function () {
    foreach ([scientistUser(['lab_id' => makeLab()->id]), staffUser(), studentUser()] as $user) {
        Livewire::actingAs($user)->test(ReportsDashboard::class)->assertForbidden();
    }
});
