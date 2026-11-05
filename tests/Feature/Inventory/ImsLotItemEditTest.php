<?php

use App\Models\AuditLog;
use App\Models\ImsLot;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Policies\ImsLotPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

/*
 * CEO 2026-11-05 (ITM-2610-063): the material's own details can be edited from the IMS lot page as well as from the items
 * register. Authorisation is ImsLotPolicy::editItem (ims.manage + the lot's own branch + a keeper of the item's CURRENT
 * kind); a move to another kind also needs that NEW kind. Only the ten fields in ItemDetailFields are ever saved.
 */

beforeEach(function () {
    $this->lab = makeLab();
    $this->otherLab = makeLab();
    $this->item = makeItem(['name_th' => 'ชื่อเดิม', 'name_en' => 'Old name', 'brand' => 'Old brand']);
    $this->lot = makeImsLot($this->lab->id, $this->item);
    $this->science = categoryId(ItemCategory::LOAN_CODE);

    $this->keeper = fn (array $kinds = ['chemical'], ?int $labId = null) => labManagerUser(['lab_id' => $labId ?? $this->lab->id, 'itemTypes' => $kinds]);
    $this->payload = fn (array $o = []) => array_merge([
        'category_id' => $this->item->category_id,
        'name_th' => 'ชื่อใหม่จากหน้า IMS',
        'name_en' => 'Old name',
        'brand' => 'Old brand',
    ], $o);
    $this->put = fn ($user, array $payload, ?ImsLot $lot = null) => $this->actingAs($user)->put(route('ims.lots.item.update', $lot ?? $this->lot), $payload);
    $this->audits = fn () => AuditLog::where('action', 'ITEM_UPDATE')->get();
});

// --- who may open / save (policy matrix) ---------------------------------------------------------------------------------------

test('ImsLotPolicy::editItem matrix: only ims.manage of the lot\'s branch who keeps the CURRENT kind; ADMIN, other branch, no kind, no ims.manage are refused', function () {
    $can = fn ($user, ImsLot $lot) => Gate::forUser($user)->allows('editItem', $lot);
    $supplyLot = makeImsLot($this->lab->id, consumableItem());
    $scienceLot = makeImsLot($this->lab->id, scienceItem());

    $chem = ($this->keeper)(['chemical']);
    $supply = ($this->keeper)(['consumable']);
    $sci = ($this->keeper)(['science']);
    $all = ($this->keeper)(['chemical', 'consumable', 'science']);
    $none = ($this->keeper)([]);
    $otherBranch = ($this->keeper)(['chemical', 'consumable', 'science'], $this->otherLab->id);
    $noBranch = labManagerUser(['lab_id' => null]);
    $auditor = auditorUser(['lab_id' => $this->lab->id, 'itemTypes' => ['chemical']]);
    $admin = adminUser(['lab_id' => $this->lab->id]);
    $scientist = scientistUser(['lab_id' => $this->lab->id]);
    $student = studentUser(['lab_id' => $this->lab->id]);

    expect($can($chem, $this->lot))->toBeTrue()
        ->and($can($auditor, $this->lot))->toBeTrue()
        ->and($can($all, $this->lot))->toBeTrue()
        ->and($can($supply, $this->lot))->toBeFalse()          // keeps only another kind
        ->and($can($sci, $this->lot))->toBeFalse()
        ->and($can($none, $this->lot))->toBeFalse()            // deny by default
        ->and($can($otherBranch, $this->lot))->toBeFalse()     // other branch, even with every kind
        ->and($can($noBranch, $this->lot))->toBeFalse()
        ->and($can($admin, $this->lot))->toBeFalse()           // ADMIN keeps every kind but has no ims.manage
        ->and($can($scientist, $this->lot))->toBeFalse()
        ->and($can($student, $this->lot))->toBeFalse()
        ->and($can($supply, $supplyLot))->toBeTrue()
        ->and($can($chem, $supplyLot))->toBeFalse()
        ->and($can($sci, $scienceLot))->toBeTrue()
        ->and($can($chem, $scienceLot))->toBeFalse()
        ->and($can($all, $scienceLot))->toBeTrue();
});

test('ADMIN is a keeper of every kind but still gets 403 from the IMS page (no ims.manage), and nothing is written', function () {
    $admin = adminUser(['lab_id' => $this->lab->id]);
    grantItemTypes($admin, ['chemical', 'consumable', 'science']);

    $this->actingAs($admin)->get(route('ims.lots.item.edit', $this->lot))->assertForbidden();
    ($this->put)($admin, ($this->payload)())->assertForbidden();

    expect($this->item->fresh()->name_th)->toBe('ชื่อเดิม')->and(($this->audits)())->toHaveCount(0);
});

test('every refused user gets 403 on both the form and the save, and the item is untouched', function (string $who) {
    $user = match ($who) {
        'other branch' => ($this->keeper)(['chemical', 'consumable', 'science'], $this->otherLab->id),
        'no kind' => ($this->keeper)([]),
        'other kind' => ($this->keeper)(['consumable']),
        'scientist' => scientistUser(['lab_id' => $this->lab->id]),
        'student' => studentUser(['lab_id' => $this->lab->id]),
        'no branch' => labManagerUser(['lab_id' => null]),
    };

    $this->actingAs($user)->get(route('ims.lots.item.edit', $this->lot))->assertForbidden();
    ($this->put)($user, ($this->payload)())->assertForbidden();

    expect($this->item->fresh()->name_th)->toBe('ชื่อเดิม')->and(($this->audits)())->toHaveCount(0);
})->with(['other branch', 'no kind', 'other kind', 'scientist', 'student', 'no branch']);

test('a guest is sent to login on both routes', function () {
    $this->get(route('ims.lots.item.edit', $this->lot))->assertRedirect(route('login'));
    $this->put(route('ims.lots.item.update', $this->lot), ($this->payload)())->assertRedirect(route('login'));
});

test('an unknown lot is a 404 (lots are addressed by ULID, never by id)', function () {
    $keeper = ($this->keeper)();

    $this->actingAs($keeper)->get(route('ims.lots.item.edit', ['ims_lot' => '01HZZZZZZZZZZZZZZZZZZZZZZZ']))->assertNotFound();
    $this->actingAs($keeper)->get('/ims/lots/'.$this->lot->id.'/item/edit')->assertNotFound();
});

// --- the page ------------------------------------------------------------------------------------------------------------------

test('the form opens for a keeper, shows the ten editable fields and the register-only ones read-only (no input to change them)', function () {
    $keeper = ($this->keeper)();

    $page = $this->actingAs($keeper)->get(route('ims.lots.item.edit', $this->lot))->assertOk();

    $page->assertSee(__('ims.item_edit.title'))->assertSee('ชื่อเดิม')->assertSee($this->item->item_code)
        ->assertSee(__('ims.item_edit.section_readonly'))
        ->assertSee('action="'.route('ims.lots.item.update', $this->lot).'"', false);
    foreach (['category_id', 'name_th', 'name_en', 'cas_no', 'formula', 'brand', 'grade', 'physical_state', 'storage_class'] as $field) {
        $page->assertSee('name="'.$field.'"', false);
    }
    foreach (['item_code', 'base_unit_id', 'package_size', 'density_g_per_ml', 'reorder_point_base', 'is_controlled', 'is_active'] as $field) {
        $page->assertDontSee('name="'.$field.'"', false);
    }
});

test('the lot list and the lot page offer the "edit material" link only to those allowed', function () {
    $keeper = ($this->keeper)();
    $other = ($this->keeper)(['consumable']);
    $link = route('ims.lots.item.edit', $this->lot);

    $this->actingAs($keeper)->get(route('ims.lots.index'))->assertOk()->assertSee($link, false);
    $this->actingAs($keeper)->get(route('ims.lots.edit', $this->lot))->assertOk()->assertSee($link, false);
    $this->actingAs($other)->get(route('ims.lots.index'))->assertOk()->assertDontSee($link, false);
});

// --- saving: happy path, validation, mass-assignment ------------------------------------------------------------------------------

test('a keeper saves the ten fields: they are stored, the answer goes back to the lot list filtered by the item code, and one audit row records the lot', function () {
    $keeper = ($this->keeper)();
    $payload = ($this->payload)([
        'name_th' => 'ชื่อใหม่', 'name_en' => 'New name', 'cas_no' => '64-17-5', 'formula' => 'C2H6O', 'brand' => 'Merck',
        'grade' => 'AR', 'physical_state' => 'liquid', 'specification' => 'ความบริสุทธิ์ 99.9%', 'storage_class' => 'flammable',
        'category_id' => categoryId('DYE'),
    ]);

    ($this->put)($keeper, $payload)
        ->assertRedirect(route('ims.lots.index', ['search' => $this->item->item_code]))
        ->assertSessionHas('status', __('ims.item_edit.saved'));

    $item = $this->item->fresh();
    expect($item->name_th)->toBe('ชื่อใหม่')->and($item->name_en)->toBe('New name')->and($item->cas_no)->toBe('64-17-5')
        ->and($item->formula)->toBe('C2H6O')->and($item->brand)->toBe('Merck')->and($item->grade)->toBe('AR')
        ->and($item->physical_state)->toBe('liquid')->and($item->specification)->toBe('ความบริสุทธิ์ 99.9%')
        ->and($item->storage_class)->toBe('flammable')->and($item->category_id)->toBe(categoryId('DYE'));

    $rows = ($this->audits)();
    expect($rows)->toHaveCount(1);
    $row = $rows->first();
    expect($row->user_id)->toBe($keeper->id)->and($row->entity_id)->toBe($item->id)
        ->and($row->new_value['source'])->toBe('ims.lot')->and($row->new_value['ims_lot'])->toBe($this->lot->ulid)
        ->and($row->old_value['fields']['name_th'])->toBe('ชื่อเดิม')->and($row->new_value['fields']['name_th'])->toBe('ชื่อใหม่')
        ->and($row->new_value['fields'])->toHaveKey('cas_no')->not->toHaveKey('base_unit_id');
});

test('the same data from the register: an edit in the IMS page and in the items register give the same stored result (one data set)', function () {
    $keeper = ($this->keeper)();

    ($this->put)($keeper, ($this->payload)(['name_th' => 'ชุดเดียว']))->assertRedirect();
    expect(Item::find($this->item->id)->name_th)->toBe('ชุดเดียว');

    $this->actingAs($keeper)->get(route('items.edit', $this->item))->assertOk()->assertSee('ชุดเดียว');
});

test('mass-assignment: fields outside the ten (item_code, units, package size, density, controlled flags, reorder point, is_active, GHS) are NOT saved', function () {
    $keeper = ($this->keeper)();
    $mg = \App\Models\Unit::where('code', 'mg')->firstOrFail();
    $before = $this->item->fresh();

    ($this->put)($keeper, ($this->payload)([
        'item_code' => 'HACKED-001', 'base_unit_id' => $mg->id, 'package_unit_id' => $mg->id, 'sub_unit_id' => $mg->id,
        'package_size' => '999', 'density_g_per_ml' => '9.99', 'reorder_point_base' => '777', 'is_controlled' => '1',
        'control_class' => 'X', 'is_active' => '0', 'expiry_date' => '2030-01-01', 'shelf_life_days_after_open' => '5',
        'ghs_codes' => ['GHS02'], 'h_statements' => ['H225'], 'p_statements' => ['P210'], 'ulid' => '01HZZZZZZZZZZZZZZZZZZZZZZZ',
        'id' => 99999, 'created_at' => '2000-01-01 00:00:00',
    ]))->assertRedirect();

    $after = $this->item->fresh();
    expect($after->name_th)->toBe('ชื่อใหม่จากหน้า IMS');   // the allowed field in the same request was saved
    foreach (['item_code', 'base_unit_id', 'package_unit_id', 'sub_unit_id', 'package_size', 'density_g_per_ml', 'reorder_point_base',
        'is_controlled', 'control_class', 'is_active', 'expiry_date', 'shelf_life_days_after_open', 'ghs_codes', 'h_statements', 'p_statements', 'ulid', 'id'] as $field) {
        expect($after->getAttribute($field))->toEqual($before->getAttribute($field), $field);
    }
    expect($after->created_at->equalTo($before->created_at))->toBeTrue();

    $fields = array_keys(($this->audits)()->firstOrFail()->new_value['fields']);
    expect($fields)->toBe(['name_th']);
});

test('saving with nothing changed is accepted and writes no audit row', function () {
    $keeper = ($this->keeper)();

    ($this->put)($keeper, ($this->payload)(['name_th' => 'ชื่อเดิม']))->assertRedirect(route('ims.lots.index', ['search' => $this->item->item_code]));

    expect(($this->audits)())->toHaveCount(0);
});

test('validation: name and category are required, category must exist, physical state must be a known one; nothing is saved', function (array $bad, string $errorOn) {
    $keeper = ($this->keeper)();

    ($this->put)($keeper, ($this->payload)($bad))->assertSessionHasErrors($errorOn);

    expect($this->item->fresh()->name_th)->toBe('ชื่อเดิม')->and(($this->audits)())->toHaveCount(0);
})->with([
    'no name' => [['name_th' => ''], 'name_th'],
    'no category' => [['category_id' => ''], 'category_id'],
    'unknown category' => [['category_id' => 999999], 'category_id'],
    'bad state' => [['physical_state' => 'plasma'], 'physical_state'],
    'name too long' => [['name_th' => str_repeat('ก', 256)], 'name_th'],
    'cas too long' => [['cas_no' => str_repeat('1', 21)], 'cas_no'],
    'category as text' => [['category_id' => "1; DROP TABLE items"], 'category_id'],
]);

// --- changing the kind -------------------------------------------------------------------------------------------------------------

test('a keeper who also keeps the NEW kind can move the item chemical -> consumable; the move is audited as a category change', function () {
    $keeper = ($this->keeper)(['chemical', 'consumable']);

    ($this->put)($keeper, ($this->payload)(['category_id' => categoryId('MATERIAL')]))->assertRedirect()->assertSessionHasNoErrors();

    expect($this->item->fresh()->kind())->toBe('consumable');
    $row = ($this->audits)()->firstOrFail();
    expect($row->old_value['fields']['category_id'])->toBe($this->item->category_id)->and($row->new_value['fields']['category_id'])->toBe(categoryId('MATERIAL'));
});

test('a keeper of only the current kind cannot move the item to a kind they do not keep: error on category_id (kind_not_managed), nothing saved', function (string $targetCode) {
    $keeper = ($this->keeper)(['chemical']);   // keeps chemical only

    ($this->put)($keeper, ($this->payload)(['category_id' => categoryId($targetCode), 'name_th' => 'ไม่ควรถูกบันทึก']))
        ->assertSessionHasErrors(['category_id' => __('ims.item_edit.kind_not_managed')]);

    expect($this->item->fresh()->name_th)->toBe('ชื่อเดิม')->and($this->item->fresh()->kind())->toBe('chemical')->and(($this->audits)())->toHaveCount(0);
})->with([
    'to consumable' => ['MATERIAL'],
    'to science' => ['LOAN_SCIENCE'],
]);

test('moving to science needs the science kind: a keeper of chemical+consumable is refused, one who also keeps science succeeds', function () {
    $noScience = ($this->keeper)(['chemical', 'consumable']);
    ($this->put)($noScience, ($this->payload)(['category_id' => $this->science]))->assertSessionHasErrors(['category_id' => __('ims.item_edit.kind_not_managed')]);
    expect($this->item->fresh()->kind())->toBe('chemical');

    $withScience = ($this->keeper)(['chemical', 'science']);
    ($this->put)($withScience, ($this->payload)(['category_id' => $this->science]))->assertRedirect()->assertSessionHasNoErrors();
    expect($this->item->fresh()->kind())->toBe('science');
});

test('a science item can only be opened by a keeper of science; moving it out needs the target kind', function () {
    $science = scienceItem(['name_th' => 'ยืมคืนทดสอบ']);
    $lot = makeImsLot($this->lab->id, $science);

    $chemOnly = ($this->keeper)(['chemical', 'consumable']);
    $this->actingAs($chemOnly)->get(route('ims.lots.item.edit', $lot))->assertForbidden();

    $sciOnly = ($this->keeper)(['science']);
    $payload = ['category_id' => categoryId('MATERIAL'), 'name_th' => 'ยืมคืนทดสอบ'];
    ($this->put)($sciOnly, $payload, $lot)->assertSessionHasErrors(['category_id' => __('ims.item_edit.kind_not_managed')]);
    expect($science->fresh()->kind())->toBe('science');

    $both = ($this->keeper)(['science', 'consumable']);
    ($this->put)($both, $payload, $lot)->assertRedirect()->assertSessionHasNoErrors();
    expect($science->fresh()->kind())->toBe('consumable');
});

test('an unfinished requisition blocks the move to/from science from the IMS page too: error on category_id with the document number, nothing saved', function (string $status) {
    $keeper = ($this->keeper)(['chemical', 'science']);
    $doc = requisitionWithItem($this->item, $status);

    $this->actingAs($keeper)->from(route('ims.lots.item.edit', $this->lot))->put(route('ims.lots.item.update', $this->lot), ($this->payload)(['category_id' => $this->science, 'name_th' => 'ไม่ควรถูกบันทึก']))
        ->assertRedirect(route('ims.lots.item.edit', $this->lot))->assertSessionHasErrors('category_id')->assertSessionHasInput('name_th', 'ไม่ควรถูกบันทึก');

    expect(session('errors')->first('category_id'))->toContain($doc->doc_no)
        ->and($this->item->fresh()->kind())->toBe('chemical')->and($this->item->fresh()->name_th)->toBe('ชื่อเดิม')->and(($this->audits)())->toHaveCount(0);
})->with(['DRAFT', 'SUBMITTED', 'APPROVED', 'PARTIALLY_ISSUED']);

test('a finished document does not block it; chemical <-> consumable is not blocked by open documents', function () {
    $keeper = ($this->keeper)(['chemical', 'consumable', 'science']);

    requisitionWithItem($this->item, 'ISSUED');
    requisitionWithItem($this->item, 'CANCELLED');
    ($this->put)($keeper, ($this->payload)(['category_id' => $this->science]))->assertRedirect()->assertSessionHasNoErrors();
    expect($this->item->fresh()->kind())->toBe('science');

    $other = makeItem();
    $otherLot = makeImsLot($this->lab->id, $other);
    requisitionWithItem($other, 'APPROVED');
    ($this->put)($keeper, ['category_id' => categoryId('MATERIAL'), 'name_th' => $other->name_th], $otherLot)->assertRedirect()->assertSessionHasNoErrors();
    expect($other->fresh()->kind())->toBe('consumable');
});

test('ImsLotPolicy is registered for ImsLot and exposes editItem (the FormRequest authorises through it, not a hard-coded role)', function () {
    expect(method_exists(ImsLotPolicy::class, 'editItem'))->toBeTrue()
        ->and(method_exists(ImsLotPolicy::class, 'transferAny'))->toBeFalse();
});
