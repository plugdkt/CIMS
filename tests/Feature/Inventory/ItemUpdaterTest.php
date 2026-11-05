<?php

use App\Domain\Inventory\Exceptions\ItemKindChangeBlockedException;
use App\Domain\Inventory\Services\ItemUpdater;
use App\Models\AuditLog;
use App\Models\ItemCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * CEO 2026-11-05 (ITM-2610-063): ItemUpdater is the ONE place an item's details change (register `items.update` and the
 * IMS lot page `ims.lot`). It (1) refuses moving an item to/from the science (loan) kind while an unfinished requisition
 * still lists it, and (2) writes one ITEM_UPDATE audit row with the before/after of only the fields that changed.
 */

beforeEach(function () {
    $this->actor = labManagerUser(['lab_id' => makeLab()->id]);
    $this->updater = app(ItemUpdater::class);
    $this->science = categoryId(ItemCategory::LOAN_CODE);
    $this->consumable = categoryId('MATERIAL');
    $this->chemical = categoryId('CHEMICAL');
    $this->update = fn ($item, array $data, string $source = ItemUpdater::SOURCE_REGISTER, array $ctx = []) => $this->updater->update($item, $data, $this->actor, $source, $ctx);
});

// --- audit ----------------------------------------------------------------------------------------------------------

test('a change writes one ITEM_UPDATE audit row: who, source, and before/after of ONLY the fields that changed', function () {
    $item = makeItem(['name_th' => 'ชื่อเดิม', 'brand' => 'ยี่ห้อเดิม', 'grade' => 'AR']);

    ($this->update)($item, ['name_th' => 'ชื่อใหม่', 'brand' => 'ยี่ห้อเดิม', 'grade' => 'AR', 'category_id' => $item->category_id]);

    $rows = AuditLog::where('action', 'ITEM_UPDATE')->where('entity_id', $item->id)->get();
    expect($rows)->toHaveCount(1);
    $row = $rows->first();
    expect($row->user_id)->toBe($this->actor->id)
        ->and($row->username)->toBe($this->actor->username)
        ->and($row->entity_type)->toBe('item')
        ->and($row->old_value)->toBe(['fields' => ['name_th' => 'ชื่อเดิม']])
        ->and($row->new_value)->toBe(['fields' => ['name_th' => 'ชื่อใหม่'], 'source' => 'items.update'])
        ->and($row->message)->toBe('items.update')
        ->and($item->fresh()->name_th)->toBe('ชื่อใหม่');
});

test('several fields changed: each is recorded with its own old and new value (null old values included)', function () {
    $item = makeItem(['brand' => null, 'formula' => null]);

    ($this->update)($item, ['brand' => 'Merck', 'formula' => 'NaOH', 'cas_no' => '1310-73-3']);

    $row = AuditLog::where('action', 'ITEM_UPDATE')->firstOrFail();
    expect($row->old_value['fields'])->toEqual(['brand' => null, 'formula' => null, 'cas_no' => '1310-73-2'])
        ->and($row->new_value['fields'])->toEqual(['brand' => 'Merck', 'formula' => 'NaOH', 'cas_no' => '1310-73-3']);
});

test('the IMS lot page passes its source and the lot to the audit row', function () {
    $item = makeItem();
    $lot = makeImsLot($this->actor->lab_id, $item);

    ($this->update)($item, ['name_th' => 'แก้จากล็อต'], ItemUpdater::SOURCE_IMS_LOT, ['ims_lot' => $lot->ulid]);

    $row = AuditLog::where('action', 'ITEM_UPDATE')->firstOrFail();
    expect($row->new_value['source'])->toBe('ims.lot')->and($row->new_value['ims_lot'])->toBe($lot->ulid)->and($row->message)->toBe('ims.lot');
});

test('saving with nothing changed writes no audit row and does not touch the item', function () {
    $item = makeItem(['name_th' => 'ไม่เปลี่ยน']);
    $updatedAt = $item->fresh()->updated_at;

    ($this->update)($item, ['name_th' => 'ไม่เปลี่ยน', 'category_id' => $item->category_id, 'cas_no' => '1310-73-2']);

    expect(AuditLog::where('action', 'ITEM_UPDATE')->count())->toBe(0)
        ->and($item->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
});

test('the audit row is rolled back with the update: a refused change leaves no audit row and no new value', function () {
    $item = makeItem(['name_th' => 'ห้ามเปลี่ยน']);
    requisitionWithItem($item, 'SUBMITTED');

    expect(fn () => ($this->update)($item, ['name_th' => 'ชื่อที่ไม่ควรถูกบันทึก', 'category_id' => $this->science]))
        ->toThrow(ItemKindChangeBlockedException::class);

    expect($item->fresh()->name_th)->toBe('ห้ามเปลี่ยน')->and($item->fresh()->category_id)->toBe($this->chemical)
        ->and(AuditLog::where('action', 'ITEM_UPDATE')->count())->toBe(0);
});

// --- guard: unfinished requisitions ---------------------------------------------------------------------------------------

test('moving an item TO science is refused while a requisition in ANY unfinished status lists it (DRAFT included), naming the document', function (string $status) {
    $item = makeItem();
    $doc = requisitionWithItem($item, $status);

    try {
        ($this->update)($item, ['category_id' => $this->science]);
        $this->fail('the move to science should have been refused for status '.$status);
    } catch (ItemKindChangeBlockedException $e) {
        expect($e->getMessage())->toContain($doc->doc_no)->and($e->docNos)->toBe([$doc->doc_no])
            ->and($e->getMessage())->toBe(__('items.kind_change_blocked', ['docs' => $doc->doc_no]));
    }

    expect($item->fresh()->category_id)->toBe($this->chemical)->and(AuditLog::where('action', 'ITEM_UPDATE')->count())->toBe(0);
})->with(['DRAFT', 'SUBMITTED', 'ADVISOR_APPROVED', 'APPROVED', 'PARTIALLY_ISSUED']);

test('moving an item FROM science is refused the same way (an old unfinished document that still lists it)', function () {
    $item = scienceItem();
    $doc = requisitionWithItem($item, 'APPROVED');

    expect(fn () => ($this->update)($item, ['category_id' => $this->consumable]))
        ->toThrow(ItemKindChangeBlockedException::class, $doc->doc_no);
    expect(fn () => ($this->update)($item, ['category_id' => $this->chemical]))
        ->toThrow(ItemKindChangeBlockedException::class, $doc->doc_no);

    expect($item->fresh()->category_id)->toBe($this->science);
});

test('finished documents (ISSUED / REJECTED / CANCELLED) do not block the move, to or from science', function (string $status) {
    $item = makeItem();
    requisitionWithItem($item, $status);

    ($this->update)($item, ['category_id' => $this->science]);
    expect($item->fresh()->kind())->toBe('science');

    ($this->update)($item, ['category_id' => $this->consumable]);
    expect($item->fresh()->kind())->toBe('consumable')
        ->and(AuditLog::where('action', 'ITEM_UPDATE')->where('entity_id', $item->id)->count())->toBe(2);
})->with(['ISSUED', 'REJECTED', 'CANCELLED']);

test('an item with no requisition at all moves freely, and an unfinished document of ANOTHER item does not block', function () {
    $item = makeItem();
    requisitionWithItem(makeItem(), 'SUBMITTED');   // someone else's open document

    ($this->update)($item, ['category_id' => $this->science]);

    expect($item->fresh()->kind())->toBe('science');
});

test('chemical <-> consumable is never blocked, even with unfinished documents', function () {
    $item = makeItem();
    requisitionWithItem($item, 'APPROVED');
    requisitionWithItem($item, 'DRAFT');

    ($this->update)($item, ['category_id' => $this->consumable]);
    expect($item->fresh()->kind())->toBe('consumable');

    ($this->update)($item, ['category_id' => $this->chemical]);
    expect($item->fresh()->kind())->toBe('chemical');
});

test('other edits of a science item, and moves between two categories of the same kind, are not blocked by open documents', function () {
    $sci = scienceItem(['name_th' => 'ยืมคืนเดิม']);
    requisitionWithItem($sci, 'APPROVED');
    ($this->update)($sci, ['name_th' => 'ยืมคืนใหม่', 'category_id' => $this->science]);
    expect($sci->fresh()->name_th)->toBe('ยืมคืนใหม่');

    $chem = makeItem();
    requisitionWithItem($chem, 'SUBMITTED');
    ($this->update)($chem, ['category_id' => categoryId('DYE')]);   // chemical -> chemical
    expect($chem->fresh()->category_id)->toBe(categoryId('DYE'));
});

test('the refusal names several documents, shows at most five, and says how many more', function () {
    $item = makeItem();
    $docs = [];
    for ($i = 0; $i < 7; $i++) {
        $docs[] = requisitionWithItem($item, 'SUBMITTED')->doc_no;
    }
    sort($docs);

    try {
        ($this->update)($item, ['category_id' => $this->science]);
        $this->fail('should be refused');
    } catch (ItemKindChangeBlockedException $e) {
        expect($e->docNos)->toBe($docs);
        foreach (array_slice($docs, 0, 5) as $doc) {
            expect($e->getMessage())->toContain($doc);
        }
        expect($e->getMessage())->not->toContain($docs[5])->and($e->getMessage())->toContain(__('items.kind_change_more', ['count' => 2]));
    }
});

test('a finished document does not show up in the refusal that names an unfinished one', function () {
    $item = makeItem();
    $done = requisitionWithItem($item, 'ISSUED');
    $open = requisitionWithItem($item, 'PARTIALLY_ISSUED');

    try {
        ($this->update)($item, ['category_id' => $this->science]);
        $this->fail('should be refused');
    } catch (ItemKindChangeBlockedException $e) {
        expect($e->docNos)->toBe([$open->doc_no])->and($e->getMessage())->not->toContain($done->doc_no);
    }
});

// --- the register route uses the same service -------------------------------------------------------------------------------

test('items.update (register) goes through the service: a refused move shows the error on category_id and keeps the form input; a good edit is audited with source items.update', function () {
    $item = makeItem(['name_th' => 'ทะเบียน']);
    $doc = requisitionWithItem($item, 'DRAFT');

    $this->actingAs($this->actor)->from(route('items.edit', $item))->put(route('items.update', $item), [
        'item_code' => $item->item_code, 'category_id' => $this->science, 'name_th' => 'ชื่อที่พิมพ์ไว้', 'base_unit_id' => $item->base_unit_id,
    ])->assertRedirect(route('items.edit', $item))->assertSessionHasErrors('category_id')->assertSessionHasInput('name_th', 'ชื่อที่พิมพ์ไว้');

    expect(session('errors')->first('category_id'))->toContain($doc->doc_no);
    expect($item->fresh()->name_th)->toBe('ทะเบียน')->and(AuditLog::where('action', 'ITEM_UPDATE')->count())->toBe(0);

    $this->actingAs($this->actor)->put(route('items.update', $item), [
        'item_code' => $item->item_code, 'category_id' => $this->chemical, 'name_th' => 'ทะเบียนใหม่', 'base_unit_id' => $item->base_unit_id,
    ])->assertRedirect(route('items.index'));

    $row = AuditLog::where('action', 'ITEM_UPDATE')->firstOrFail();
    expect($row->user_id)->toBe($this->actor->id)->and($row->new_value['source'])->toBe('items.update')
        ->and($row->old_value['fields'])->toBe(['name_th' => 'ทะเบียน'])->and($row->new_value['fields'])->toBe(['name_th' => 'ทะเบียนใหม่']);
});

test('items.update with nothing changed writes no audit row; changing a register-only field (reorder point) is audited too', function () {
    $item = makeItem();
    $same = [
        'item_code' => $item->item_code, 'category_id' => $item->category_id, 'name_th' => $item->name_th, 'name_en' => $item->name_en,
        'cas_no' => $item->cas_no, 'base_unit_id' => $item->base_unit_id,
    ];

    $this->actingAs($this->actor)->put(route('items.update', $item), $same)->assertRedirect(route('items.index'));
    expect(AuditLog::where('action', 'ITEM_UPDATE')->count())->toBe(0);

    $this->actingAs($this->actor)->put(route('items.update', $item), $same + ['reorder_point_base' => '25'])->assertRedirect(route('items.index'));
    $row = AuditLog::where('action', 'ITEM_UPDATE')->firstOrFail();
    expect(array_keys($row->new_value['fields']))->toBe(['reorder_point_base']);
});

test('X4: items.update re-sent with the SAME data, decimal fields included (written in other formats), changes nothing and writes no ITEM_UPDATE row', function () {
    $item = makeItem([
        'package_size' => '500.5', 'density_g_per_ml' => '1.25', 'reorder_point_base' => '25.5',
    ]);
    $item = $item->fresh();
    $base = [
        'item_code' => $item->item_code, 'category_id' => $item->category_id, 'name_th' => $item->name_th, 'name_en' => $item->name_en,
        'cas_no' => $item->cas_no, 'base_unit_id' => $item->base_unit_id, 'is_active' => 1,
    ];

    // Same numbers as the form would send them: plain, with trailing zeros, and with the six decimals the database returns.
    $variants = [
        ['package_size' => '500.5', 'density_g_per_ml' => '1.25', 'reorder_point_base' => '25.5'],
        ['package_size' => '500.500000', 'density_g_per_ml' => '1.250000', 'reorder_point_base' => '25.500000'],
        ['package_size' => '500.50', 'density_g_per_ml' => '1.2500', 'reorder_point_base' => '25.50'],
    ];

    foreach ($variants as $decimals) {
        $this->actingAs($this->actor)->put(route('items.update', $item), $base + $decimals)->assertRedirect(route('items.index'));
    }

    expect(AuditLog::where('action', 'ITEM_UPDATE')->count())->toBe(0);
    $fresh = $item->fresh();
    expect((float) $fresh->package_size)->toBe(500.5)
        ->and((float) $fresh->density_g_per_ml)->toBe(1.25)
        ->and((float) $fresh->reorder_point_base)->toBe(25.5);
});

test('items.update is for holders of item.manage (warehouse managers): a SCIENTIST and a plain ADMIN get 403 and nothing is written', function () {
    $item = makeItem(['name_th' => 'ห้ามแก้']);
    $payload = ['item_code' => $item->item_code, 'category_id' => $item->category_id, 'name_th' => 'ถูกแก้', 'base_unit_id' => $item->base_unit_id];

    foreach ([scientistUser(), adminUser()] as $user) {
        $this->actingAs($user)->put(route('items.update', $item), $payload)->assertForbidden();
    }

    expect($item->fresh()->name_th)->toBe('ห้ามแก้')->and(AuditLog::where('action', 'ITEM_UPDATE')->count())->toBe(0);
});
