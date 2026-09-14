<?php

use App\Models\Requisition;
use App\Models\Role;
use App\Models\StockLedger;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function studentUser(array $overrides = []): User
{
    $advisor = User::factory()->create(['person_type' => 'LECTURER']);
    $advisor->roles()->attach(Role::where('code', 'ADVISOR')->firstOrFail());

    $user = User::factory()->create(array_merge([
        'person_type' => 'STUDENT',
        'phone_encrypted' => '0812345678',
        'person_code_encrypted' => '6512345',
        'program' => 'เคมี',
        'faculty' => 'วิทยาศาสตร์',
        'advisor_id' => $advisor->id,
        'profile_completed_at' => now(),
    ], $overrides));
    $user->roles()->attach(Role::where('code', 'STUDENT')->firstOrFail());

    return $user;
}

function staffUser(array $overrides = []): User
{
    $user = User::factory()->create(array_merge([
        'person_type' => 'STAFF',
        'phone_encrypted' => '0898765432',
        'program' => null,
        'faculty' => 'วิทยาศาสตร์',
        'profile_completed_at' => now(),
    ], $overrides));
    $user->roles()->attach(Role::where('code', 'STAFF')->firstOrFail());

    return $user;
}

test('a user without requisition.create gets 403 on the create page', function () {
    $manager = labManagerUser();

    $this->actingAs($manager)->get(route('requisitions.create'))->assertStatus(403);
});

test('a STUDENT with an incomplete profile is redirected to complete-profile instead of creating a requisition (BR-11 point 4)', function () {
    $student = studentUser(['profile_completed_at' => null]);

    $this->actingAs($student)->get(route('requisitions.create'))
        ->assertRedirect(route('account.complete-profile'));
});

test('a STUDENT can create a DRAFT requisition with requester fields snapshotted from their profile (FR-RQ-01)', function () {
    $lab = makeLab();
    $student = studentUser(['lab_id' => $lab->id]);

    $response = $this->actingAs($student)->post(route('requisitions.store'), [
        'request_type' => ['CHEMICAL'],
        'purpose_type' => 'TEACHING',
        'purpose_detail' => 'วิชาเคมีทั่วไป',
    ]);

    $requisition = Requisition::where('requester_id', $student->id)->firstOrFail();
    $response->assertRedirect(route('requisitions.show', $requisition));

    expect($requisition->doc_no)->toStartWith('REQ-');
    expect($requisition->status)->toBe('DRAFT');
    expect($requisition->lab_id)->toBe($lab->id);
    expect($requisition->requester_status)->toBe('STUDENT');
    expect($requisition->requester_phone)->toBe('0812345678');
    expect($requisition->student_code)->toBe('6512345');
    expect($requisition->program)->toBe('เคมี');
    expect($requisition->faculty)->toBe('วิทยาศาสตร์');
    expect($requisition->advisor_id)->toBe($student->advisor_id);
    expect($requisition->request_type)->toBe(['CHEMICAL']);
});

test('a STAFF requester has no student_code snapshotted', function () {
    $lab = makeLab();
    $staff = staffUser(['lab_id' => $lab->id]);

    $this->actingAs($staff)->post(route('requisitions.store'), [
        'request_type' => ['CONSUMABLE'],
        'purpose_type' => 'RESEARCH',
    ]);

    $requisition = Requisition::where('requester_id', $staff->id)->firstOrFail();
    expect($requisition->student_code)->toBeNull();
    expect($requisition->requester_status)->toBe('STAFF');
    expect($requisition->lab_id)->toBe($lab->id);
});

test('a requisition\'s lab_id is always the requester\'s own branch, never a posted value (branch-scoped access control)', function () {
    $ownLab = makeLab();
    $otherLab = makeLab();
    $student = studentUser(['lab_id' => $ownLab->id]);

    $this->actingAs($student)->post(route('requisitions.store'), [
        'lab_id' => $otherLab->id, // must be ignored — snapshotted server-side
        'request_type' => ['CHEMICAL'],
        'purpose_type' => 'TEACHING',
    ]);

    $requisition = Requisition::where('requester_id', $student->id)->firstOrFail();
    expect($requisition->lab_id)->toBe($ownLab->id);
});

test('a requester with no branch assigned is redirected to the pending-lab page instead of the create form', function () {
    $student = studentUser(['lab_id' => null]);

    $this->actingAs($student)->get(route('requisitions.create'))
        ->assertRedirect(route('account.pending-lab'));

    $this->actingAs($student)->post(route('requisitions.store'), [
        'request_type' => ['CHEMICAL'],
        'purpose_type' => 'TEACHING',
    ])->assertStatus(403);

    expect(Requisition::where('requester_id', $student->id)->count())->toBe(0);
});

test('adding a line item computes qty_requested_base in the item base unit (FR-RQ-04)', function () {
    $student = studentUser();
    $requisition = makeRequisition($student);
    $item = makeItem(['base_unit_id' => Unit::where('code', 'g')->value('id')]);
    $kg = Unit::where('code', 'kg')->firstOrFail();

    $this->actingAs($student)->post(route('requisitions.items.store', $requisition), [
        'item_id' => $item->id,
        'qty_requested' => '2.5',
        'unit_id' => $kg->id,
        'reference_doc' => 'CHM101',
    ]);

    $line = $requisition->items()->firstOrFail();
    expect($line->qty_requested)->toBe('2.500000');
    expect($line->qty_requested_base)->toBe('2500.000000');
    expect($line->reference_doc)->toBe('CHM101');
});

test('adding a line item crosses dimensions using the item density', function () {
    $student = studentUser();
    $requisition = makeRequisition($student);
    $item = makeItem([
        'base_unit_id' => Unit::where('code', 'g')->value('id'),
        'density_g_per_ml' => '0.789',
    ]);
    $mL = Unit::where('code', 'mL')->firstOrFail();

    $this->actingAs($student)->post(route('requisitions.items.store', $requisition), [
        'item_id' => $item->id,
        'qty_requested' => '100',
        'unit_id' => $mL->id,
    ]);

    $line = $requisition->items()->firstOrFail();
    expect($line->qty_requested_base)->toBe('78.900000');
});

test('a line item can be removed while the requisition is still DRAFT', function () {
    $student = studentUser();
    $requisition = makeRequisition($student);
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();

    $this->actingAs($student)->post(route('requisitions.items.store', $requisition), [
        'item_id' => $item->id,
        'qty_requested' => '5',
        'unit_id' => $g->id,
    ]);
    $line = $requisition->items()->firstOrFail();

    $this->actingAs($student)->delete(route('requisitions.items.destroy', [$requisition, $line]));

    expect($requisition->items()->count())->toBe(0);
});

test('submitting a requisition with no line items is rejected', function () {
    $student = studentUser();
    $requisition = makeRequisition($student);

    $this->actingAs($student)->post(route('requisitions.submit', $requisition))
        ->assertSessionHasErrors('submit');

    expect($requisition->fresh()->status)->toBe('DRAFT');
});

test('submitting a requisition with at least one line moves it to SUBMITTED (BR-01)', function () {
    $student = studentUser();
    $requisition = makeRequisition($student);
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $this->actingAs($student)->post(route('requisitions.items.store', $requisition), [
        'item_id' => $item->id,
        'qty_requested' => '5',
        'unit_id' => $g->id,
    ]);

    $this->actingAs($student)->post(route('requisitions.submit', $requisition))
        ->assertRedirect(route('requisitions.show', $requisition));

    $fresh = $requisition->fresh();
    expect($fresh->status)->toBe('SUBMITTED');
    expect($fresh->submitted_at)->not->toBeNull();
});

test('a DRAFT or SUBMITTED requisition can be cancelled by its own requester (BR-01)', function () {
    $student = studentUser();
    $draft = makeRequisition($student);

    $this->actingAs($student)->post(route('requisitions.cancel', $draft))
        ->assertRedirect(route('requisitions.index'));
    expect($draft->fresh()->status)->toBe('CANCELLED');

    $submitted = makeRequisition($student);
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $this->actingAs($student)->post(route('requisitions.items.store', $submitted), [
        'item_id' => $item->id, 'qty_requested' => '5', 'unit_id' => $g->id,
    ]);
    $this->actingAs($student)->post(route('requisitions.submit', $submitted));

    $this->actingAs($student)->post(route('requisitions.cancel', $submitted));
    expect($submitted->fresh()->status)->toBe('CANCELLED');
});

test('a requester with view_own cannot open another requester\'s requisition, but view_all can', function () {
    $studentA = studentUser();
    $studentB = studentUser();
    $requisition = makeRequisition($studentA);

    $this->actingAs($studentB)->get(route('requisitions.show', $requisition))->assertStatus(403);

    $scientist = scientistUser();
    $this->actingAs($scientist)->get(route('requisitions.show', $requisition))->assertStatus(200);
});

test('the item balance endpoint returns the current ledger balance for FR-RQ-05', function () {
    $student = studentUser();
    $item = makeItem(['base_unit_id' => Unit::where('code', 'g')->value('id')]);
    $unit = Unit::where('code', 'g')->firstOrFail();

    StockLedger::create([
        'item_id' => $item->id,
        'txn_date' => now()->toDateString(),
        'txn_type' => 'OPENING',
        'qty_in_base' => '0',
        'qty_out_base' => '0',
        'balance_base' => '42.500000',
        'display_unit_id' => $unit->id,
        'created_by' => $student->id,
        'created_at' => now(),
        'prev_row_hash' => null,
        'row_hash' => str_repeat('a', 64),
    ]);

    $this->actingAs($student)->getJson(route('requisitions.items.balance', $item))
        ->assertOk()
        ->assertJson(['balance' => '42.500000', 'unit' => 'g']);
});

function makeRequisition(User $requester, array $overrides = []): Requisition
{
    $lab = makeLab();

    return Requisition::create(array_merge([
        'doc_no' => 'REQ-2569-'.str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
        'lab_id' => $lab->id,
        'doc_date' => now()->toDateString(),
        'requester_id' => $requester->id,
        'requester_status' => $requester->person_type,
        'requester_phone' => $requester->phone_encrypted,
        'student_code' => $requester->person_type === 'STUDENT' ? $requester->person_code_encrypted : null,
        'program' => $requester->program,
        'faculty' => $requester->faculty,
        'advisor_id' => $requester->advisor_id,
        'request_type' => ['CHEMICAL'],
        'purpose_type' => 'TEACHING',
        'status' => 'DRAFT',
    ], $overrides));
}
