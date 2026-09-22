<?php

use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a scientist can record a return via the HTTP layer', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '50.000000');
    $line = $requisition->items->first();
    $container = stockedContainer($line->item_id, '100.000000', $staff);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);
    $g = Unit::where('code', 'g')->firstOrFail();
    app(\App\Domain\Requisition\Services\IssueService::class)->issue($line, $container, '50.000000', $g, $scientist, $staff, hash('sha256', 'x'));

    $this->actingAs($scientist)->post(route('requisitions.items.return', [$requisition, $line]), [
        'container_id' => $container->id,
        'qty_returned' => '20.000000',
        'unit_id' => $g->id,
    ])->assertRedirect(route('requisitions.issue.create', $requisition));

    expect($container->fresh()->remaining_qty_base)->toBe('70.000000');
});

test('the issue/return page stays reachable once a requisition is fully ISSUED, for returns', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '50.000000');
    $line = $requisition->items->first();
    $container = stockedContainer($line->item_id, '100.000000', $staff);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);
    $g = Unit::where('code', 'g')->firstOrFail();
    app(\App\Domain\Requisition\Services\IssueService::class)->issue($line, $container, '50.000000', $g, $scientist, $staff, hash('sha256', 'x'));
    expect($requisition->fresh()->status)->toBe('ISSUED');

    $this->actingAs($scientist)->get(route('requisitions.issue.create', $requisition))
        ->assertOk()
        ->assertSee(__('requisitions.return_title'));
});

test('a user without requisition.issue gets 403 on the return route', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '50.000000');
    $line = $requisition->items->first();
    $container = stockedContainer($line->item_id, '100.000000', $staff);
    $labManager = labManagerUser();
    $g = Unit::where('code', 'g')->firstOrFail();

    $this->actingAs($labManager)->post(route('requisitions.items.return', [$requisition, $line]), [
        'container_id' => $container->id,
        'qty_returned' => '10.000000',
        'unit_id' => $g->id,
    ])->assertStatus(403);
});

test('returning more than was issued surfaces a form error, not a 500', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '50.000000');
    $line = $requisition->items->first();
    $container = stockedContainer($line->item_id, '100.000000', $staff);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);
    $g = Unit::where('code', 'g')->firstOrFail();
    app(\App\Domain\Requisition\Services\IssueService::class)->issue($line, $container, '50.000000', $g, $scientist, $staff, hash('sha256', 'x'));

    $this->actingAs($scientist)->post(route('requisitions.items.return', [$requisition, $line]), [
        'container_id' => $container->id,
        'qty_returned' => '999.000000',
        'unit_id' => $g->id,
    ])->assertSessionHasErrors('return');
});
