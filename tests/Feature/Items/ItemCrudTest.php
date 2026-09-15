<?php

use App\Livewire\Items\ItemTable;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

if (! function_exists('labManagerUser')) {
    function labManagerUser(array $overrides = []): User
    {
        $user = User::factory()->create($overrides);
        $user->roles()->attach(Role::where('code', 'LAB_MANAGER')->firstOrFail());

        return $user;
    }
}

if (! function_exists('scientistUser')) {
    function scientistUser(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('code', 'SCIENTIST')->firstOrFail());

        return $user;
    }
}

if (! function_exists('makeItem')) {
    function makeItem(array $overrides = []): Item
    {
        $category = ItemCategory::where('code', 'CHEMICAL')->firstOrFail();
        $baseUnit = Unit::where('code', 'g')->firstOrFail();

        return Item::create(array_merge([
            'item_code' => 'CHM-'.fake()->unique()->numerify('#####'),
            'category_id' => $category->id,
            'name_th' => 'โซเดียมไฮดรอกไซด์',
            'name_en' => 'Sodium Hydroxide',
            'cas_no' => '1310-73-2',
            'base_unit_id' => $baseUnit->id,
            'is_active' => true,
        ], $overrides));
    }
}

test('a user without item.view gets 403 on the item index', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('items.index'))->assertStatus(403);
});

test('SCIENTIST (item.view only) can see the list but not create items', function () {
    $scientist = scientistUser();
    $item = makeItem();

    $this->actingAs($scientist)
        ->get(route('items.index'))
        ->assertOk()
        ->assertSee($item->name_th);

    $this->actingAs($scientist)->get(route('items.create'))->assertStatus(403);
});

test('LAB_MANAGER can create a new item', function () {
    $manager = labManagerUser();
    $category = ItemCategory::where('code', 'CHEMICAL')->firstOrFail();
    $unit = Unit::where('code', 'g')->firstOrFail();

    $response = $this->actingAs($manager)->post(route('items.store'), [
        'item_code' => 'CHM-00001',
        'category_id' => $category->id,
        'name_th' => 'เอทานอล',
        'base_unit_id' => $unit->id,
    ]);

    $item = Item::where('item_code', 'CHM-00001')->first();
    expect($item)->not->toBeNull();
    $response->assertRedirect(route('items.index'));
    $response->assertSessionHas('status');
});

test('item_code must be unique', function () {
    $manager = labManagerUser();
    $existing = makeItem(['item_code' => 'CHM-00099']);

    $this->actingAs($manager)->post(route('items.store'), [
        'item_code' => 'CHM-00099',
        'category_id' => $existing->category_id,
        'name_th' => 'ทดสอบซ้ำ',
        'base_unit_id' => $existing->base_unit_id,
    ])->assertSessionHasErrors('item_code');
});

test('LAB_MANAGER can update an existing item', function () {
    $manager = labManagerUser();
    $item = makeItem();

    $this->actingAs($manager)->put(route('items.update', $item), [
        'item_code' => $item->item_code,
        'category_id' => $item->category_id,
        'name_th' => 'โซเดียมไฮดรอกไซด์ (แก้ไข)',
        'base_unit_id' => $item->base_unit_id,
    ])->assertRedirect(route('items.index'));

    expect($item->fresh()->name_th)->toBe('โซเดียมไฮดรอกไซด์ (แก้ไข)');
});

test('full-text search finds items by Thai name', function () {
    $scientist = scientistUser();
    makeItem(['name_th' => 'โซเดียมไฮดรอกไซด์', 'item_code' => 'CHM-A1']);
    makeItem(['name_th' => 'กรดไฮโดรคลอริก', 'name_en' => 'Hydrochloric Acid', 'item_code' => 'CHM-A2']);

    Livewire::actingAs($scientist)
        ->test(ItemTable::class)
        ->set('search', 'โซเดียม')
        ->assertSee('โซเดียมไฮดรอกไซด์')
        ->assertDontSee('กรดไฮโดรคลอริก');
});

test('search also matches by item_code and CAS number', function () {
    $scientist = scientistUser();
    makeItem(['item_code' => 'CHM-UNIQUE9', 'cas_no' => '1310-73-2']);

    Livewire::actingAs($scientist)
        ->test(ItemTable::class)
        ->set('search', 'CHM-UNIQUE9')
        ->assertSee('CHM-UNIQUE9');
});

test('LAB_MANAGER can create an item with specification and without base_unit_id', function () {
    $manager = labManagerUser();
    $category = ItemCategory::where('code', 'CHEMICAL')->firstOrFail();

    $response = $this->actingAs($manager)->post(route('items.store'), [
        'item_code' => 'CHM-SPEC-001',
        'category_id' => $category->id,
        'name_th' => 'เอทานอลสำหรับสเปกกลาง',
        'specification' => "ความบริสุทธิ์ไม่ต่ำกว่า 99.8% (Assay by GC)\nบรรจุขวดแก้วสีชาขนาด 2.5 ลิตร สำหรับงาน HPLC",
    ]);

    $item = Item::where('item_code', 'CHM-SPEC-001')->first();
    expect($item)->not->toBeNull();
    expect($item->specification)->toContain('Assay by GC');
    expect($item->base_unit_id)->toBeNull();
    $response->assertRedirect(route('items.index'));
    $response->assertSessionHas('status');
});

test('authorized user can trigger PubChem sync for an item', function () {
    $manager = labManagerUser();
    $item = makeItem(['cas_no' => '64-17-5', 'formula' => null]);

    \Illuminate\Support\Facades\Http::fake([
        '*rest/pug/compound/xref/RegistryID/*' => \Illuminate\Support\Facades\Http::response([
            'PropertyTable' => ['Properties' => [['CID' => 702, 'Title' => 'Ethanol', 'MolecularFormula' => 'C2H6O']]],
        ], 200),
        '*rest/pug_view/*' => \Illuminate\Support\Facades\Http::response(['Record' => ['Section' => []]], 200),
    ]);

    $response = $this->actingAs($manager)->post(route('items.sync-pubchem', $item));

    $response->assertRedirect(route('items.show', $item));
    $response->assertSessionHas('status');
    expect($item->fresh()->formula)->toBe('C2H6O');
});

test('unauthorized user cannot trigger PubChem sync', function () {
    $user = \App\Models\User::factory()->create();
    $item = makeItem(['cas_no' => '64-17-5']);

    $this->actingAs($user)->post(route('items.sync-pubchem', $item))->assertForbidden();
});
