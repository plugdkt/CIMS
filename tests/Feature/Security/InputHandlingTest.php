<?php

use App\Livewire\Items\ItemTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// ST-01: a SQLi-shaped query string must not error out or leak data — Eloquent's
// parameter binding (AGENT RULE #3: no raw concatenated SQL) treats it as a literal.
test('a SQL injection payload in the item search matches nothing and does not error (ST-01)', function () {
    $scientist = scientistUser();
    makeItem(['name_th' => 'โซเดียมไฮดรอกไซด์', 'item_code' => 'CHM-SEC1']);

    Livewire::actingAs($scientist)
        ->test(ItemTable::class)
        ->set('search', "' OR '1'='1")
        ->assertOk()
        ->assertDontSee('โซเดียมไฮดรอกไซด์');
});

// ST-02: a script tag stored in a free-text field must render as inert escaped text,
// never as an executable <script> element, on any page that echoes it back.
test('a script tag in an item name is rendered as escaped text, never executed (ST-02)', function () {
    $manager = labManagerUser();
    $item = makeItem(['name_th' => '<script>alert(1)</script>', 'item_code' => 'CHM-SEC2']);

    $response = $this->actingAs($manager)->get(route('items.show', $item));

    $response->assertOk();
    $response->assertDontSee('<script>alert(1)</script>', false);
    $response->assertSee('<script>alert(1)</script>');
});
