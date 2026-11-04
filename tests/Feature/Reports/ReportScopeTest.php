<?php

use App\Domain\Reporting\Services\SelfApprovalReport;
use App\Livewire\Reports\ReportsDashboard;
use App\Models\StockTake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;

uses(RefreshDatabase::class);

/*
 * (1) A warehouse manager with no lab_id gets NO data from the reports page and the Excel/PDF routes (never "every
 * branch"). (2) The self-approval report narrows a warehouse manager to the kinds they hold; scientists/ADMIN are not
 * narrowed; a manager with no kinds gets nothing. (Written without being run: the coordinator runs the suite.)
 */

if (! function_exists('selfKindFixture')) {
    /** One branch with a self-approved chemical requisition and a self-approved consumable one. @return array{0: \App\Models\Lab, 1: string, 2: string} */
    function selfKindFixture(): array
    {
        $lab = makeLab();
        $make = function (string $category, string $docNo) use ($lab) {
            $requester = scientistUser(['lab_id' => $lab->id, 'person_type' => 'STAFF']);
            requisitionWithLines($requester, [$category], ['lab_id' => $lab->id, 'doc_no' => $docNo, 'status' => 'APPROVED', 'scientist_id' => $requester->id]);
        };
        $make('CHEMICAL', 'REQ-KIND-CHEM');
        $make('MATERIAL', 'REQ-KIND-SUPP');

        return [$lab, 'REQ-KIND-CHEM', 'REQ-KIND-SUPP'];
    }
}

test('self-approval report: a manager sees only the kinds they hold, no kinds = nothing, null (scientist/ADMIN) = every kind', function () {
    [$lab, $chem, $supp] = selfKindFixture();
    $docs = fn (?array $kinds) => (new SelfApprovalReport())->rows($lab->id, null, null, $kinds)->map(fn ($r) => $r['requisition']->doc_no)->sort()->values()->all();

    expect($docs(['chemical']))->toBe([$chem]);
    expect($docs(['consumable']))->toBe([$supp]);
    expect($docs(['chemical', 'consumable']))->toBe([$chem, $supp]);
    expect($docs([]))->toBe([]);
    expect($docs(null))->toBe([$chem, $supp]);
});

test('self-approval tab on screen: chemical-only manager sees only chemical, a manager with no kinds sees nothing, both-kinds sees both', function () {
    [$lab, $chem, $supp] = selfKindFixture();

    Livewire::actingAs(auditorUser(['lab_id' => $lab->id, 'itemTypes' => ['chemical']]))->test(ReportsDashboard::class)
        ->set('tab', 'self_approval')->assertSee($chem)->assertDontSee($supp);
    Livewire::actingAs(labManagerUser(['lab_id' => $lab->id, 'itemTypes' => ['consumable']]))->test(ReportsDashboard::class)
        ->set('tab', 'self_approval')->assertSee($supp)->assertDontSee($chem);
    Livewire::actingAs(auditorUser(['lab_id' => $lab->id, 'itemTypes' => []]))->test(ReportsDashboard::class)
        ->set('tab', 'self_approval')->assertDontSee($chem)->assertDontSee($supp);
    Livewire::actingAs(auditorUser(['lab_id' => $lab->id]))->test(ReportsDashboard::class)
        ->set('tab', 'self_approval')->assertSee($chem)->assertSee($supp);
    Livewire::actingAs(adminUser())->test(ReportsDashboard::class)
        ->set('tab', 'self_approval')->assertSee($chem)->assertSee($supp);
});

test('a manager with no lab_id sees nothing on any report tab, even with a branch picked in the URL, including self_approval and a stock-take round', function () {
    [$lab, $chem] = selfKindFixture();
    $location = makeLocationForLab($lab);
    $item = makeItem(['name_th' => 'ZNOLABITEM']);
    makeContainer(['item_id' => $item->id, 'location_id' => $location->id, 'status' => 'SEALED', 'remaining_qty_base' => '10.000000']);
    $take = StockTake::create(['doc_no' => 'ST-NOLAB-1', 'lab_id' => $lab->id, 'count_date' => now()->toDateString(), 'status' => 'APPROVED', 'created_by' => scientistUser()->id]);
    $noBranch = auditorUser(['lab_id' => null]);

    foreach (['item_stock_summary', 'expiring_stock', 'below_reorder', 'dead_stock', 'controlled_substances', 'usage_summary', 'self_approval'] as $tab) {
        Livewire::actingAs($noBranch)->test(ReportsDashboard::class)->set('labId', $lab->id)->set('tab', $tab)
            ->assertDontSee('ZNOLABITEM')->assertDontSee($chem);
    }
    Livewire::actingAs($noBranch)->test(ReportsDashboard::class)->set('tab', 'stock_take_variance')->set('stockTakeUlid', $take->ulid)
        ->assertDontSee('ST-NOLAB-1');
});

test('a manager with no lab_id downloading Excel gets an empty export; the same download for the branch manager has the stock (control)', function () {
    $lab = makeLab();
    $location = makeLocationForLab($lab);
    $item = makeItem(['name_th' => 'ZNOLABITEM']);
    makeContainer(['item_id' => $item->id, 'location_id' => $location->id, 'status' => 'SEALED', 'remaining_qty_base' => '10.000000']);
    \App\Models\StockLedger::create([
        'item_id' => $item->id, 'txn_date' => now()->toDateString(), 'txn_type' => 'RECEIVE',
        'qty_in_base' => '10', 'qty_out_base' => '0', 'balance_base' => '10.000000',
        'display_unit_id' => \App\Models\Unit::where('code', 'g')->value('id'), 'created_by' => scientistUser()->id,
        'created_at' => now(), 'prev_row_hash' => null, 'row_hash' => str_repeat('e', 64),
    ]); // the export lists items with a real ledger row

    Excel::fake();
    Excel::matchByRegex();
    $this->actingAs(auditorUser(['lab_id' => null]))->get(route('reports.item-stock-summary.excel', ['lab_id' => $lab->id]))->assertOk();
    Excel::assertDownloaded('/.*/', fn ($export) => $export->results()->isEmpty());

    Excel::fake();
    Excel::matchByRegex();
    $this->actingAs(auditorUser(['lab_id' => $lab->id]))->get(route('reports.item-stock-summary.excel'))->assertOk();
    Excel::assertDownloaded('/.*/', fn ($export) => $export->results()->isNotEmpty());
});

test('a manager with no lab_id cannot download another branch\'s stock-take round (Excel or PDF): 403', function () {
    $lab = makeLab();
    $take = StockTake::create(['doc_no' => 'ST-NOLAB-2', 'lab_id' => $lab->id, 'count_date' => now()->toDateString(), 'status' => 'APPROVED', 'created_by' => scientistUser()->id]);
    $noBranch = auditorUser(['lab_id' => null]);

    $this->actingAs($noBranch)->get(route('reports.stock-take.excel', $take))->assertForbidden();
    $this->actingAs($noBranch)->get(route('reports.stock-take.pdf', $take))->assertForbidden();
});
