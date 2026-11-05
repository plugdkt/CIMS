<?php

use App\Domain\Requisition\Services\RequisitionService;
use App\Models\Container;
use App\Models\ImsLot;
use App\Models\ImsMovement;
use App\Models\IssueTransaction;
use App\Models\StockLedger;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * ITM-2610-062: issue-all dispenses from WORKING stock only and never cuts IMS automatically.
 * A line that working stock cannot cover is refused WHOLE (no partial issue); other lines of the same submission are
 * not affected; a line that fails half-way rolls back only itself; the requisition's status never moves for a refused line.
 */

beforeEach(function () {
    $this->lab = makeLab();
    $this->requester = staffUser(['lab_id' => $this->lab->id]);
    $this->scientist = scientistUser(['lab_id' => $this->lab->id]);
    $this->g = Unit::where('code', 'g')->firstOrFail();
    $this->service = app(RequisitionService::class);

    // An APPROVED requisition with one line per [item, qty] (lines are added while still a DRAFT).
    $this->approved = function (array $lines, string $status = 'APPROVED') {
        $requisition = makeRequisition($this->requester, ['lab_id' => $this->lab->id]);
        foreach ($lines as [$item, $qty]) {
            $this->service->addLine($requisition, $item, $this->g, $qty);
        }
        $requisition->update(['status' => $status]);

        return $requisition->fresh(['items']);
    };

    $this->issue = fn ($requisition, array $qtyByLine, array $extra = []) => $this->actingAs($this->scientist)->post(route('requisitions.issue-all', $requisition), [
        'lines' => collect($qtyByLine)->map(fn ($qty) => ['qty' => (string) $qty, 'unit_id' => $this->g->id])->all(),
    ] + $extra);

    // Seeded through the ledger (RECEIVE row first) so a later ISSUE keeps chk_ledger_balance happy.
    $this->working = fn ($item, string $qty) => stockedContainer($item->id, $qty, $this->requester, $this->lab->id);

    $this->snapshot = fn () => [
        'ledger' => StockLedger::count(),
        'issues' => IssueTransaction::count(),
        'ims_issue' => ImsMovement::where('type', 'ISSUE')->count(),
        'containers' => Container::count(),
        'lots' => ImsLot::orderBy('id')->pluck('qty_remaining_base')->map(fn ($q) => (string) $q)->all(),
        'container_qty' => Container::orderBy('id')->pluck('remaining_qty_base')->map(fn ($q) => (string) $q)->all(),
    ];
});

test('a line working stock cannot cover is refused whole: no IMS cut, no new container, no ledger row, status unchanged', function () {
    $item = makeItem(['name_th' => 'สารขาดคลังใช้งาน']);
    makeImsLot($this->lab->id, $item, '4', '500', ['location_id' => makeLocationForLab($this->lab)->id]);   // IMS could cover it
    ($this->working)($item, '100.000000');
    $requisition = ($this->approved)([[$item, '300']]);
    $line = $requisition->items->first();
    $before = ($this->snapshot)();

    ($this->issue)($requisition, [$line->id => '300'])->assertRedirect(route('requisitions.issue.create', $requisition))->assertSessionHasErrors('issue');

    expect(session('errors')->get('issue')[0])->toContain('สารขาดคลังใช้งาน')->toContain(__('working_stock.error.issue_working_short'));
    expect(($this->snapshot)())->toBe($before);
    expect($requisition->fresh()->status)->toBe('APPROVED')
        ->and(bccomp((string) ($line->fresh()->qty_issued_base ?? '0'), '0', 6))->toBe(0);
});

test('a PARTIALLY_ISSUED requisition stays PARTIALLY_ISSUED (not ISSUED, not APPROVED) when its next line is refused', function () {
    $item = makeItem();
    makeImsLot($this->lab->id, $item, '4', '500');
    $requisition = ($this->approved)([[$item, '400']], 'PARTIALLY_ISSUED');
    $line = $requisition->items->first();
    $line->update(['qty_issued_base' => '100.000000']);          // 300 g still to issue
    ($this->working)($item, '50.000000');                         // working stock has 50 g
    $before = ($this->snapshot)();

    ($this->issue)($requisition, [$line->id => '300'])->assertSessionHasErrors('issue');

    expect($requisition->fresh()->status)->toBe('PARTIALLY_ISSUED')
        ->and($line->fresh()->qty_issued_base)->toBe('100.000000');
    expect(($this->snapshot)())->toBe($before);
});

test('one line short and one covered in the same submission: the covered line is issued, the short one is refused, the status shows the part that happened', function () {
    $covered = makeItem(['name_th' => 'สารพอ']);
    $short = makeItem(['name_th' => 'สารไม่พอ']);
    makeImsLot($this->lab->id, $short, '4', '500');
    $containerCovered = ($this->working)($covered, '500.000000');
    ($this->working)($short, '20.000000');
    $requisition = ($this->approved)([[$covered, '100'], [$short, '200']]);
    [$lineCovered, $lineShort] = [$requisition->items[0], $requisition->items[1]];
    $imsBefore = ImsLot::orderBy('id')->pluck('qty_remaining_base')->map(fn ($q) => (string) $q)->all();

    $response = ($this->issue)($requisition, [$lineCovered->id => '100', $lineShort->id => '200']);
    $response->assertRedirect(route('requisitions.issue.create', $requisition))
        ->assertSessionHas('status', __('requisitions.issue_all_recorded', ['count' => 1]))
        ->assertSessionHasErrors('issue');

    $errors = session('errors')->get('issue');
    expect($errors)->toHaveCount(1)->and($errors[0])->toContain('สารไม่พอ');

    expect($lineCovered->fresh()->qty_issued_base)->toBe('100.000000')
        ->and($containerCovered->fresh()->remaining_qty_base)->toBe('400.000000')
        ->and(bccomp((string) ($lineShort->fresh()->qty_issued_base ?? '0'), '0', 6))->toBe(0)
        ->and(IssueTransaction::where('requisition_item_id', $lineShort->id)->count())->toBe(0)
        ->and($requisition->fresh()->status)->toBe('PARTIALLY_ISSUED');
    // IMS was never touched, and the short item's working stock stays at 20 g.
    expect(ImsLot::orderBy('id')->pluck('qty_remaining_base')->map(fn ($q) => (string) $q)->all())->toBe($imsBefore);
    expect(ImsMovement::where('type', 'ISSUE')->count())->toBe(0);

    // The short line is refused again later until a keeper moves stock — and can then be issued from the same page.
    ($this->working)($short, '500.000000');
    ($this->issue)($requisition, [$lineShort->id => '200'])->assertSessionHasNoErrors();
    expect($requisition->fresh()->status)->toBe('ISSUED');
});

test('a line that fails half-way rolls back only itself: its first container is not left issued, the other line stays issued', function () {
    $ok = makeItem(['name_th' => 'สารแถวสำเร็จ']);
    $over = makeItem(['name_th' => 'สารแถวล้ม']);
    $okContainer = ($this->working)($ok, '300.000000');
    $a = ($this->working)($over, '200.000000');
    $b = ($this->working)($over, '200.000000');
    // Approved 300 g; asking 400 g = two containers of 200 g. The first fits under the ceiling, the second pushes the line
    // past it (BR-04: more than the approved quantity needs a remark) and throws AFTER the first container was issued.
    $requisition = ($this->approved)([[$ok, '100'], [$over, '300']]);
    [$lineOk, $lineOver] = [$requisition->items[0], $requisition->items[1]];
    $ledgerBeforeOver = StockLedger::where('item_id', $over->id)->count();

    ($this->issue)($requisition, [$lineOk->id => '100', $lineOver->id => '400'])
        ->assertSessionHasErrors('issue')
        ->assertSessionHas('status', __('requisitions.issue_all_recorded', ['count' => 1]));

    expect(session('errors')->get('issue'))->toHaveCount(1);
    // Rolled back inside the failing line: both containers and the line are as they were.
    expect($a->fresh()->remaining_qty_base)->toBe('200.000000')
        ->and($b->fresh()->remaining_qty_base)->toBe('200.000000')
        ->and(IssueTransaction::where('requisition_item_id', $lineOver->id)->count())->toBe(0)
        ->and(StockLedger::where('item_id', $over->id)->count())->toBe($ledgerBeforeOver)
        ->and(bccomp((string) ($lineOver->fresh()->qty_issued_base ?? '0'), '0', 6))->toBe(0);
    // The other line was issued and stays issued.
    expect($lineOk->fresh()->qty_issued_base)->toBe('100.000000')
        ->and($okContainer->fresh()->remaining_qty_base)->toBe('200.000000')
        ->and($requisition->fresh()->status)->toBe('PARTIALLY_ISSUED');
});

test('working stock of ANOTHER branch does not cover a line, and neither does expired stock', function () {
    $item = makeItem();
    makeContainer(['item_id' => $item->id, 'location_id' => makeLocationForLab(makeLab())->id, 'remaining_qty_base' => '500.000000']);
    makeContainer(['item_id' => $item->id, 'location_id' => makeLocationForLab($this->lab)->id, 'remaining_qty_base' => '500.000000', 'expiry_date' => now()->subDay()->toDateString()]);
    $requisition = ($this->approved)([[$item, '100']]);
    $before = ($this->snapshot)();

    ($this->issue)($requisition, [$requisition->items->first()->id => '100'])->assertSessionHasErrors('issue');

    expect(($this->snapshot)())->toBe($before)->and($requisition->fresh()->status)->toBe('APPROVED');
});

test('a line fully covered by working stock still issues exactly as before, and writes nothing to IMS', function () {
    $item = makeItem();
    $lot = makeImsLot($this->lab->id, $item, '4', '500');
    $container = ($this->working)($item, '500.000000');
    $requisition = ($this->approved)([[$item, '120']]);
    $line = $requisition->items->first();

    ($this->issue)($requisition, [$line->id => '120'])->assertSessionHasNoErrors();

    expect($line->fresh()->qty_issued_base)->toBe('120.000000')
        ->and($container->fresh()->remaining_qty_base)->toBe('380.000000')
        ->and($requisition->fresh()->status)->toBe('ISSUED')
        ->and((float) $lot->fresh()->qty_remaining_base)->toEqual(2000.0)
        ->and(ImsMovement::where('ims_lot_id', $lot->id)->count())->toBe(0);
});
