<?php

declare(strict_types=1);

// ITM-2610-042: GRN access is branch-scoped; ADMIN sees every branch.

use App\Models\GoodsReceipt;
use App\Models\StockLedger;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function grnBranchSetup(): array
{
    $labX = makeLab(['name_th' => 'สาขา เอ็กซ์']);
    $labY = makeLab(['name_th' => 'สาขา วาย']);

    return [$labX, $labY, makeDraftGrn(['lab_id' => $labX->id]), makeDraftGrn(['lab_id' => $labY->id])];
}

function grnWithLine(GoodsReceipt $grn): void
{
    $g = Unit::where('code', 'g')->firstOrFail();
    app(\App\Domain\Inventory\Services\GoodsReceiptService::class)->addLine($grn, makeItem(), $g, 1, '10');
}

test('branch managers (LAB_MANAGER, AUDITOR) can view and edit own-branch GRN only', function (string $role) {
    [$labX, , $grnX, $grnY] = grnBranchSetup();
    $user = $role === 'LAB_MANAGER' ? labManagerUser(['lab_id' => $labX->id]) : auditorUser(['lab_id' => $labX->id]);
    $payload = fn (GoodsReceipt $g) => ['receipt_date' => now()->toDateString(), 'lab_id' => $g->lab_id];

    $this->actingAs($user)->get(route('goods-receipts.show', $grnX))->assertOk();
    $this->actingAs($user)->put(route('goods-receipts.update', $grnX), $payload($grnX))->assertRedirect();

    $this->actingAs($user)->get(route('goods-receipts.show', $grnY))->assertForbidden();
    $this->actingAs($user)->put(route('goods-receipts.update', $grnY), $payload($grnY))->assertForbidden();
    $this->actingAs($user)->get(route('goods-receipts.labels', [$grnY, '40x25']))->assertForbidden();
})->with(['LAB_MANAGER', 'AUDITOR']);

test('other-branch GRN: add line, delete line, confirm and cancel are refused and change nothing', function () {
    [$labX, , , $grnY] = grnBranchSetup();
    $user = auditorUser(['lab_id' => $labX->id]);
    grnWithLine($grnY);
    $line = $grnY->items()->firstOrFail();
    $g = Unit::where('code', 'g')->firstOrFail();

    $this->actingAs($user)->post(route('goods-receipts.items.store', $grnY), [
        'item_id' => makeItem()->id, 'container_count' => 1, 'qty_per_container' => '1', 'unit_id' => $g->id,
    ])->assertForbidden();
    $this->actingAs($user)->delete(route('goods-receipts.items.destroy', [$grnY, $line]))->assertForbidden();
    $this->actingAs($user)->post(route('goods-receipts.confirm', $grnY))->assertForbidden();
    $this->actingAs($user)->post(route('goods-receipts.cancel', $grnY))->assertForbidden();

    expect($grnY->fresh()->status)->toBe('DRAFT');
    expect($grnY->items()->count())->toBe(1);
    expect(StockLedger::where('ref_type', 'GRN')->where('ref_id', $grnY->id)->count())->toBe(0);
});

test('own-branch GRN can be confirmed by a branch manager', function () {
    [$labX, , $grnX] = grnBranchSetup();
    $user = labManagerUser(['lab_id' => $labX->id]);
    grnWithLine($grnX);

    $this->actingAs($user)->post(route('goods-receipts.confirm', $grnX))->assertRedirect();

    expect($grnX->fresh()->status)->toBe('CONFIRMED');
});

test('index lists only own-branch GRNs for a branch manager', function () {
    [$labX, , $grnX, $grnY] = grnBranchSetup();
    $user = auditorUser(['lab_id' => $labX->id]);

    $this->actingAs($user)->get(route('goods-receipts.index'))
        ->assertOk()
        ->assertSee($grnX->doc_no)
        ->assertDontSee($grnY->doc_no);
});

test('create form offers only the own branch to a branch manager, all branches to ADMIN', function () {
    [$labX, $labY] = grnBranchSetup();

    $this->actingAs(auditorUser(['lab_id' => $labX->id]))->get(route('goods-receipts.create'))
        ->assertOk()->assertSee($labX->name_th)->assertDontSee($labY->name_th);

    $this->actingAs(adminUser())->get(route('goods-receipts.create'))
        ->assertOk()->assertSee($labX->name_th)->assertSee($labY->name_th);
});

test('store with another branch lab_id is refused with a Thai message and creates nothing', function () {
    [$labX, $labY] = grnBranchSetup();
    $user = auditorUser(['lab_id' => $labX->id]);
    $before = GoodsReceipt::count();

    $this->actingAs($user)->post(route('goods-receipts.store'), [
        'receipt_date' => now()->toDateString(), 'lab_id' => $labY->id,
    ])->assertSessionHasErrors(['lab_id' => __('goods_receipts.validation.lab_not_own_branch')]);

    expect(GoodsReceipt::count())->toBe($before);
});

test('update cannot move an own-branch GRN to another branch', function () {
    [$labX, $labY, $grnX] = grnBranchSetup();
    $user = auditorUser(['lab_id' => $labX->id]);

    $this->actingAs($user)->put(route('goods-receipts.update', $grnX), [
        'receipt_date' => now()->toDateString(), 'lab_id' => $labY->id,
    ])->assertSessionHasErrors('lab_id');

    expect($grnX->fresh()->lab_id)->toBe($labX->id);
});

test('a non-ADMIN user without a branch cannot reach GRN pages or create', function () {
    [$labX, , $grnX] = grnBranchSetup();
    $user = auditorUser(['lab_id' => null]);

    $this->actingAs($user)->get(route('goods-receipts.create'))->assertForbidden();
    $this->actingAs($user)->post(route('goods-receipts.store'), [
        'receipt_date' => now()->toDateString(), 'lab_id' => $labX->id,
    ])->assertForbidden();
    $this->actingAs($user)->get(route('goods-receipts.show', $grnX))->assertForbidden();
});

test('ADMIN sees all branches in the index, can view, create for any branch and confirm any GRN', function () {
    [, $labY, $grnX, $grnY] = grnBranchSetup();
    $admin = adminUser();
    grnWithLine($grnY);

    $this->actingAs($admin)->get(route('goods-receipts.index'))
        ->assertOk()->assertSee($grnX->doc_no)->assertSee($grnY->doc_no);
    $this->actingAs($admin)->get(route('goods-receipts.show', $grnX))->assertOk();

    $this->actingAs($admin)->post(route('goods-receipts.store'), [
        'receipt_date' => now()->toDateString(), 'lab_id' => $labY->id,
    ])->assertRedirect();
    expect(GoodsReceipt::where('lab_id', $labY->id)->count())->toBe(2);

    $this->actingAs($admin)->post(route('goods-receipts.confirm', $grnY))->assertRedirect();
    expect($grnY->fresh()->status)->toBe('CONFIRMED');
    expect(StockLedger::where('ref_type', 'GRN')->where('ref_id', $grnY->id)->count())->toBe(1);
});

test('a non-ADMIN user without a branch opens the index: empty list, no-branch message, no create button', function () {
    grnBranchSetup();
    $user = auditorUser(['lab_id' => null]);

    $this->actingAs($user)->get(route('goods-receipts.index'))
        ->assertOk()
        ->assertSee(__('goods_receipts.no_branch_assigned'))
        ->assertDontSee(route('goods-receipts.create'));
});

test('ADMIN can edit the header and cancel a DRAFT GRN of another branch', function () {
    [, $labY, , $grnY] = grnBranchSetup();
    $admin = adminUser();

    $this->actingAs($admin)->put(route('goods-receipts.update', $grnY), [
        'receipt_date' => now()->toDateString(), 'lab_id' => $labY->id, 'supplier' => 'ผู้ขายทดสอบ',
    ])->assertRedirect(route('goods-receipts.show', $grnY));
    expect($grnY->fresh()->supplier)->toBe('ผู้ขายทดสอบ');

    $this->actingAs($admin)->post(route('goods-receipts.cancel', $grnY))
        ->assertRedirect(route('goods-receipts.index'));
    expect($grnY->fresh()->status)->toBe('CANCELLED');
});
