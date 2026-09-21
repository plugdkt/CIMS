<?php

use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a user without location.manage gets 403 on the location index', function () {
    $scientist = scientistUser();

    $this->actingAs($scientist)->get(route('locations.index'))->assertStatus(403);
    $this->actingAs($scientist)->get(route('locations.create'))->assertStatus(403);
});

test('a LAB_MANAGER can create a location with no parent at any level, not just BUILDING', function () {
    $lab = makeLab();
    $manager = labManagerUser(['lab_id' => $lab->id]);

    $response = $this->actingAs($manager)->post(route('locations.store'), [
        'code' => 'SHELF-STANDALONE',
        'name' => 'ชั้นวางอิสระ',
        'level_type' => 'SHELF',
        'lab_id' => $lab->id,
    ]);

    $shelf = Location::where('code', 'SHELF-STANDALONE')->first();
    expect($shelf)->not->toBeNull();
    expect($shelf->parent_id)->toBeNull();
    $response->assertRedirect(route('locations.edit', $shelf));
});

test('a location can parent to any other level in the same branch, not just the level directly above', function () {
    $lab = makeLab();
    $manager = labManagerUser(['lab_id' => $lab->id]);
    $building = Location::create(['code' => 'BLD-A', 'name' => 'อาคาร A', 'level_type' => 'BUILDING', 'lab_id' => $lab->id]);

    // A SHELF parenting directly to a BUILDING, skipping ROOM/CABINET entirely.
    $this->actingAs($manager)->post(route('locations.store'), [
        'code' => 'SHELF-A',
        'name' => 'ชั้นวาง A',
        'level_type' => 'SHELF',
        'parent_id' => $building->id,
        'lab_id' => $lab->id,
    ])->assertSessionDoesntHaveErrors('parent_id');

    expect(Location::where('code', 'SHELF-A')->first()->parent_id)->toBe($building->id);
});

test('a location cannot parent to a location in a different branch', function () {
    $lab = makeLab();
    $otherLab = makeLab();
    $manager = labManagerUser(['lab_id' => $lab->id]);
    $otherBuilding = Location::create(['code' => 'BLD-OTHER', 'name' => 'อาคารสาขาอื่น', 'level_type' => 'BUILDING', 'lab_id' => $otherLab->id]);

    $this->actingAs($manager)->post(route('locations.store'), [
        'code' => 'ROOM-CROSS',
        'name' => 'ห้องข้ามสาขา',
        'level_type' => 'ROOM',
        'parent_id' => $otherBuilding->id,
        'lab_id' => $lab->id,
    ])->assertSessionHasErrors('parent_id');

    expect(Location::where('code', 'ROOM-CROSS')->exists())->toBeFalse();
});

test('code must be unique', function () {
    $lab = makeLab();
    $manager = labManagerUser(['lab_id' => $lab->id]);
    Location::create(['code' => 'BLD-F', 'name' => 'อาคาร F', 'level_type' => 'BUILDING', 'lab_id' => $lab->id]);

    $this->actingAs($manager)->post(route('locations.store'), [
        'code' => 'BLD-F',
        'name' => 'อาคาร F ซ้ำ',
        'level_type' => 'BUILDING',
        'lab_id' => $lab->id,
    ])->assertSessionHasErrors('code');
});

test('saving a location with a storage_class that conflicts with a sibling flashes a BR-10 warning', function () {
    $lab = makeLab();
    $manager = labManagerUser(['lab_id' => $lab->id]);
    $building = Location::create(['code' => 'BLD-G', 'name' => 'อาคาร G', 'level_type' => 'BUILDING', 'lab_id' => $lab->id]);
    $room = Location::create(['code' => 'ROOM-G', 'name' => 'ห้อง G', 'level_type' => 'ROOM', 'parent_id' => $building->id, 'lab_id' => $lab->id]);
    Location::create([
        'code' => 'CAB-G1', 'name' => 'ตู้กรด', 'level_type' => 'CABINET',
        'parent_id' => $room->id, 'storage_class' => 'ACID', 'lab_id' => $lab->id,
    ]);

    $response = $this->actingAs($manager)->post(route('locations.store'), [
        'code' => 'CAB-G2',
        'name' => 'ตู้เบส',
        'level_type' => 'CABINET',
        'parent_id' => $room->id,
        'storage_class' => 'BASE',
        'lab_id' => $lab->id,
    ]);

    $response->assertSessionHas('conflicts', ['ACID']);
});

test('branch scoping: a LAB_MANAGER can only create a location in their own branch', function () {
    $lab = makeLab();
    $otherLab = makeLab();
    $manager = labManagerUser(['lab_id' => $lab->id]);

    $this->actingAs($manager)->post(route('locations.store'), [
        'code' => 'BLD-OWN',
        'name' => 'อาคารของสาขาตนเอง',
        'level_type' => 'BUILDING',
        'lab_id' => $lab->id,
    ])->assertSessionDoesntHaveErrors('lab_id');
    expect(Location::where('code', 'BLD-OWN')->exists())->toBeTrue();

    $this->actingAs($manager)->post(route('locations.store'), [
        'code' => 'BLD-OTHER',
        'name' => 'อาคารของสาขาอื่น',
        'level_type' => 'BUILDING',
        'lab_id' => $otherLab->id,
    ])->assertSessionHasErrors('lab_id');
    expect(Location::where('code', 'BLD-OTHER')->exists())->toBeFalse();
});

test('branch scoping: a LAB_MANAGER gets 403 editing a location in a different branch', function () {
    $lab = makeLab();
    $otherLab = makeLab();
    $manager = labManagerUser(['lab_id' => $lab->id]);
    $otherBuilding = Location::create(['code' => 'BLD-X', 'name' => 'อาคาร X', 'level_type' => 'BUILDING', 'lab_id' => $otherLab->id]);

    $this->actingAs($manager)->get(route('locations.edit', $otherBuilding))->assertStatus(403);
});

test('the location tree only shows the viewer\'s own branch, never another branch\'s locations', function () {
    $lab = makeLab();
    $otherLab = makeLab();
    $manager = labManagerUser(['lab_id' => $lab->id]);
    $ownBuilding = Location::create(['code' => 'BLD-OWN2', 'name' => 'อาคารสาขาตนเอง', 'level_type' => 'BUILDING', 'lab_id' => $lab->id]);
    $otherBuilding = Location::create(['code' => 'BLD-OTHER2', 'name' => 'อาคารสาขาอื่น', 'level_type' => 'BUILDING', 'lab_id' => $otherLab->id]);

    $this->actingAs($manager)
        ->get(route('locations.index'))
        ->assertOk()
        ->assertSee($ownBuilding->name)
        ->assertDontSee($otherBuilding->name);
});

test('a branch manager with no lab_id assigned sees a clear message instead of an empty tree', function () {
    $manager = labManagerUser(['lab_id' => null]);

    $this->actingAs($manager)
        ->get(route('locations.index'))
        ->assertOk()
        ->assertSee(__('locations.no_own_lab'));

    $this->actingAs($manager)
        ->get(route('locations.create'))
        ->assertOk()
        ->assertSee(__('locations.no_own_lab'));
});
