<?php

use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a user without location.manage gets 403 on the location index', function () {
    $scientist = scientistUser();

    $this->actingAs($scientist)->get(route('locations.index'))->assertStatus(403);
    $this->actingAs($scientist)->get(route('locations.create'))->assertStatus(403);
});

test('LAB_MANAGER can create a BUILDING with no parent', function () {
    $manager = labManagerUser();

    $response = $this->actingAs($manager)->post(route('locations.store'), [
        'code' => 'BLD-A',
        'name' => 'อาคาร A',
        'level_type' => 'BUILDING',
    ]);

    $building = Location::where('code', 'BLD-A')->first();
    expect($building)->not->toBeNull();
    expect($building->parent_id)->toBeNull();
    $response->assertRedirect(route('locations.edit', $building));
});

test('a BUILDING with a parent is rejected', function () {
    $manager = labManagerUser();
    $building = Location::create(['code' => 'BLD-B', 'name' => 'อาคาร B', 'level_type' => 'BUILDING']);

    $this->actingAs($manager)->post(route('locations.store'), [
        'code' => 'BLD-C',
        'name' => 'อาคาร C',
        'level_type' => 'BUILDING',
        'parent_id' => $building->id,
    ])->assertSessionHasErrors('parent_id');
});

test('a ROOM without a parent is rejected', function () {
    $manager = labManagerUser();

    $this->actingAs($manager)->post(route('locations.store'), [
        'code' => 'ROOM-A',
        'name' => 'ห้อง A',
        'level_type' => 'ROOM',
    ])->assertSessionHasErrors('parent_id');
});

test('a ROOM whose parent is not a BUILDING is rejected', function () {
    $manager = labManagerUser();
    $building = Location::create(['code' => 'BLD-D', 'name' => 'อาคาร D', 'level_type' => 'BUILDING']);
    $room = Location::create(['code' => 'ROOM-D', 'name' => 'ห้อง D', 'level_type' => 'ROOM', 'parent_id' => $building->id]);
    $cabinet = Location::create(['code' => 'CAB-D', 'name' => 'ตู้ D', 'level_type' => 'CABINET', 'parent_id' => $room->id]);

    $this->actingAs($manager)->post(route('locations.store'), [
        'code' => 'ROOM-E',
        'name' => 'ห้อง E',
        'level_type' => 'ROOM',
        'parent_id' => $cabinet->id,
    ])->assertSessionHasErrors('parent_id');
});

test('code must be unique', function () {
    $manager = labManagerUser();
    Location::create(['code' => 'BLD-F', 'name' => 'อาคาร F', 'level_type' => 'BUILDING']);

    $this->actingAs($manager)->post(route('locations.store'), [
        'code' => 'BLD-F',
        'name' => 'อาคาร F ซ้ำ',
        'level_type' => 'BUILDING',
    ])->assertSessionHasErrors('code');
});

test('saving a location with a storage_class that conflicts with a sibling flashes a BR-10 warning', function () {
    $manager = labManagerUser();
    $building = Location::create(['code' => 'BLD-G', 'name' => 'อาคาร G', 'level_type' => 'BUILDING']);
    $room = Location::create(['code' => 'ROOM-G', 'name' => 'ห้อง G', 'level_type' => 'ROOM', 'parent_id' => $building->id]);
    Location::create([
        'code' => 'CAB-G1', 'name' => 'ตู้กรด', 'level_type' => 'CABINET',
        'parent_id' => $room->id, 'storage_class' => 'ACID',
    ]);

    $response = $this->actingAs($manager)->post(route('locations.store'), [
        'code' => 'CAB-G2',
        'name' => 'ตู้เบส',
        'level_type' => 'CABINET',
        'parent_id' => $room->id,
        'storage_class' => 'BASE',
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

test('LAB_MANAGER can view the location tree', function () {
    $manager = labManagerUser();
    $building = Location::create(['code' => 'BLD-H', 'name' => 'อาคาร H', 'level_type' => 'BUILDING']);

    $this->actingAs($manager)
        ->get(route('locations.index'))
        ->assertOk()
        ->assertSee($building->name);
});
