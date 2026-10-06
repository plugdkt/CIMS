<?php

use App\Livewire\Items\ItemTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('ItemTable resets pagination to page 1 when searching on subsequent page', function () {
    $scientist = scientistUser();

    for ($i = 1; $i <= 30; $i++) {
        makeItem(['name_th' => "Chemical $i", 'name_en' => "Chemical $i"]);
    }
    makeItem(['name_th' => 'Alcohol 70%', 'name_en' => 'Alcohol 70%']);

    Livewire::actingAs($scientist)
        ->test(ItemTable::class)
        ->call('setPage', 2)
        ->set('search', 'Alcohol')
        ->assertSee('Alcohol 70%')
        ->assertDontSee(__('items.no_results'));
});

test('ItemTable can be filtered by chemical type', function () {
    $scientist = scientistUser();
    $media = \App\Models\ItemCategory::where('code', 'MEDIA')->firstOrFail();

    makeItem(['name_th' => 'วุ้นเลี้ยงเชื้อ NA', 'name_en' => 'Nutrient Agar', 'category_id' => $media->id]);
    makeItem(['name_th' => 'โซเดียมคลอไรด์', 'name_en' => 'Sodium Chloride']);

    Livewire::actingAs($scientist)
        ->test(ItemTable::class)
        ->set('categoryId', $media->id)
        ->assertSee('วุ้นเลี้ยงเชื้อ NA')
        ->assertDontSee('โซเดียมคลอไรด์');
});
