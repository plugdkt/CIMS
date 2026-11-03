<?php

use App\Domain\Requisition\Exceptions\ExcessiveIssueQuantityException;
use App\Domain\Requisition\Services\IssueService;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * ITM-2610-052 phase 3: a warehouse manager (LAB_MANAGER / AUDITOR) works on their own branch AND
 * only on the kinds of stock they are assigned (user_item_types). Deny by default: no row, no access.
 *   - view (show page, PDF): own branch + any kind of the requisition's lines;
 *   - decide (approve / reject): own branch + EVERY kind in it (a legacy mixed requisition needs both);
 *   - overage approval (BR-04): same "every kind" rule;
 *   - lists and numbers (requisitions.manage, badges): the same viewable set.
 * LAB_MANAGER and AUDITOR must behave identically for the same kinds. People who are not branch
 * managers (ADMIN, dispensers, requesters) are not narrowed by kind here.
 */

// manager kinds => [label, kinds they hold]
dataset('manager kinds', [
    'chemical only' => [['chemical']],
    'consumable only' => [['consumable']],
    'both kinds' => [['chemical', 'consumable']],
    'no rows (deny by default)' => [[]],
]);

// requisition flavour => lines' category codes and the kinds those lines make up
const KIND_REQS = [
    'chemical' => [['CHEMICAL'], ['chemical']],
    'consumable' => [['MATERIAL'], ['consumable']],
    'legacy mixed' => [['CHEMICAL', 'GLASSWARE'], ['chemical', 'consumable']],
];

if (! function_exists('kindCanView')) {
    function kindCanView(array $held, array $kinds): bool
    {
        return array_intersect($kinds, $held) !== [];
    }
}

if (! function_exists('kindCanDecide')) {
    function kindCanDecide(array $held, array $kinds): bool
    {
        return $held !== [] && array_diff($kinds, $held) === [];
    }
}

if (! function_exists('kindRequisition')) {
    /** A SUBMITTED requisition by a STAFF member of the branch (no advisor step), lines of the given categories. */
    function kindRequisition(\App\Models\Lab $lab, array $categories, array $overrides = []): \App\Models\Requisition
    {
        return requisitionWithLines(staffUser(['lab_id' => $lab->id]), $categories, array_merge(['lab_id' => $lab->id, 'status' => 'SUBMITTED'], $overrides));
    }
}

test('view: show page and PDF follow the kinds the manager holds, for LAB_MANAGER and AUDITOR alike', function (array $held) {
    $lab = makeLab();

    foreach (['labManagerUser', 'auditorUser'] as $factory) {
        $manager = $factory(['lab_id' => $lab->id, 'itemTypes' => $held]);

        foreach (KIND_REQS as $name => [$categories, $kinds]) {
            $requisition = kindRequisition($lab, $categories);

            $show = $this->actingAs($manager)->get(route('requisitions.show', $requisition));
            $pdf = $this->actingAs($manager)->get(route('requisitions.pdf', $requisition));

            if (kindCanView($held, $kinds)) {
                $show->assertOk();
                expect($pdf->status())->not->toBe(403, "{$factory} {$name}: PDF must open");
            } else {
                $show->assertForbidden();
                $pdf->assertForbidden();
            }
        }
    }
})->with('manager kinds');

test('view: a manager of another branch is refused whatever kinds they hold', function () {
    $lab = makeLab();
    $other = auditorUser(['lab_id' => makeLab()->id]);   // both kinds, wrong branch

    foreach (KIND_REQS as [$categories]) {
        $requisition = kindRequisition($lab, $categories);

        $this->actingAs($other)->get(route('requisitions.show', $requisition))->assertForbidden();
        $this->actingAs($other)->get(route('requisitions.pdf', $requisition))->assertForbidden();
    }
});

test('decide: approving through the page route is allowed only when every kind is held; a refused POST changes nothing', function (array $held) {
    $lab = makeLab();

    foreach (['labManagerUser', 'auditorUser'] as $factory) {
        $manager = $factory(['lab_id' => $lab->id, 'itemTypes' => $held]);

        foreach (KIND_REQS as $name => [$categories, $kinds]) {
            $requisition = kindRequisition($lab, $categories);

            $response = $this->actingAs($manager)->post(route('requisitions.scientist-decide', $requisition), ['decision' => 'APPROVE']);

            $fresh = $requisition->fresh();
            if (kindCanDecide($held, $kinds)) {
                $response->assertRedirect(route('requisitions.show', $requisition));
                expect($fresh->status)->toBe('APPROVED', "{$factory} {$name}");
                expect($fresh->scientist_id)->toBe($manager->id);
            } else {
                $response->assertForbidden();
                expect($fresh->status)->toBe('SUBMITTED', "{$factory} {$name}: status must not change");
                expect($fresh->scientist_id)->toBeNull();
                expect(\App\Models\RequisitionApproval::where('requisition_id', $requisition->id)->count())->toBe(0);
            }
        }
    }
})->with('manager kinds');

test('decide: rejecting is gated the same way as approving (direct POST)', function () {
    $lab = makeLab();
    $chemOnly = auditorUser(['lab_id' => $lab->id, 'itemTypes' => ['chemical']]);
    $supply = kindRequisition($lab, ['MATERIAL']);
    $mixed = kindRequisition($lab, ['CHEMICAL', 'GLASSWARE']);

    foreach ([$supply, $mixed] as $requisition) {
        $this->actingAs($chemOnly)->post(route('requisitions.scientist-decide', $requisition), ['decision' => 'REJECT', 'reason' => 'ไม่เห็นควร'])->assertForbidden();
        expect($requisition->fresh()->status)->toBe('SUBMITTED');
    }

    // The same manager can still reject a chemical one.
    $chem = kindRequisition($lab, ['CHEMICAL']);
    $this->actingAs($chemOnly)->post(route('requisitions.scientist-decide', $chem), ['decision' => 'REJECT', 'reason' => 'ไม่เห็นควร'])->assertRedirect();
    expect($chem->fresh()->status)->toBe('REJECTED');
});

test('legacy mixed requisition: a single-kind manager can see it but not decide it; one holding both can do both', function () {
    $lab = makeLab();
    $mixed = kindRequisition($lab, ['CHEMICAL', 'GLASSWARE']);

    foreach (['chemical', 'consumable'] as $kind) {
        $single = labManagerUser(['lab_id' => $lab->id, 'itemTypes' => [$kind]]);
        $this->actingAs($single)->get(route('requisitions.show', $mixed))->assertOk();
        $this->actingAs($single)->post(route('requisitions.scientist-decide', $mixed), ['decision' => 'APPROVE'])->assertForbidden();
    }
    expect($mixed->fresh()->status)->toBe('SUBMITTED');

    $both = labManagerUser(['lab_id' => $lab->id]);
    $this->actingAs($both)->post(route('requisitions.scientist-decide', $mixed), ['decision' => 'APPROVE'])->assertRedirect();
    expect($mixed->fresh()->status)->toBe('APPROVED');
});

test('people who are not warehouse managers are not narrowed by kind: a requester sees their own, a dispenser works their branch', function () {
    $lab = makeLab();
    $staff = staffUser(['lab_id' => $lab->id]);
    $own = requisitionWithLines($staff, ['MATERIAL'], ['lab_id' => $lab->id, 'status' => 'SUBMITTED']);
    $approved = kindRequisition($lab, ['CHEMICAL'], ['status' => 'APPROVED']);

    $this->actingAs($staff)->get(route('requisitions.show', $own))->assertOk();

    $dispenser = scientistUser(['lab_id' => $lab->id]);   // no rows in user_item_types, and none needed
    expect($dispenser->itemTypes()->count())->toBe(0);
    $this->actingAs($dispenser)->get(route('requisitions.show', $approved))->assertOk();
});

test('requisitions.manage lists only what the manager may see: own branch, kinds held (mixed shows for either), nothing without rows', function (array $held) {
    $lab = makeLab();
    $otherLab = makeLab();
    $manager = auditorUser(['lab_id' => $lab->id, 'itemTypes' => $held]);

    $docs = [
        'chemical' => 'REQ-9501-00001', 'consumable' => 'REQ-9501-00002', 'legacy mixed' => 'REQ-9501-00003',
    ];
    foreach (KIND_REQS as $name => [$categories]) {
        kindRequisition($lab, $categories, ['doc_no' => $docs[$name]]);
    }
    kindRequisition($otherLab, ['CHEMICAL'], ['doc_no' => 'REQ-9501-00009']);   // another branch

    $response = $this->actingAs($manager)->get(route('requisitions.manage', ['tab' => 'waiting']))->assertOk();

    foreach (KIND_REQS as $name => [$categories, $kinds]) {
        kindCanView($held, $kinds) ? $response->assertSee($docs[$name]) : $response->assertDontSee($docs[$name]);
    }
    $response->assertDontSee('REQ-9501-00009');

    if ($held === []) {
        $response->assertSee(__('admin.no_item_types_notice'));
    } else {
        $response->assertDontSee(__('admin.no_item_types_notice'));
    }
})->with('manager kinds');

test('the warehouse overview warns a manager who has no kinds assigned, and only them', function () {
    $lab = makeLab();

    $this->actingAs(auditorUser(['lab_id' => $lab->id, 'itemTypes' => []]))->get(route('warehouse.overview'))
        ->assertOk()->assertSee(__('admin.no_item_types_notice'));
    $this->actingAs(auditorUser(['lab_id' => $lab->id, 'itemTypes' => ['consumable']]))->get(route('warehouse.overview'))
        ->assertOk()->assertDontSee(__('admin.no_item_types_notice'));
    $this->actingAs(adminUser())->get(route('warehouse.overview'))->assertOk()->assertDontSee(__('admin.no_item_types_notice'));
});

test('badges: the approval number counts the viewable queue of the branch, and the card shows which kinds the manager holds', function () {
    $lab = makeLab();
    kindRequisition($lab, ['CHEMICAL']);
    kindRequisition($lab, ['MATERIAL']);
    kindRequisition($lab, ['CHEMICAL', 'GLASSWARE']);
    kindRequisition(makeLab(), ['CHEMICAL']);   // another branch: never counted

    $badges = fn ($manager) => collect(homeCards($this->actingAs($manager)->get('/')->assertOk()->getContent()))
        ->firstWhere('title', __('nav.card_ops'))['badges'];

    $label = fn (string $kind) => __('requisitions.request_type_'.$kind);

    $chem = $badges(auditorUser(['lab_id' => $lab->id, 'itemTypes' => ['chemical']]));
    expect($chem)->toContain(__('nav.badge_approval', ['count' => 2]))   // chemical + mixed
        ->toContain(__('nav.badge_managed_types', ['types' => $label('chemical')]));

    $supply = $badges(labManagerUser(['lab_id' => $lab->id, 'itemTypes' => ['consumable']]));
    expect($supply)->toContain(__('nav.badge_approval', ['count' => 2]))   // consumable + mixed
        ->toContain(__('nav.badge_managed_types', ['types' => $label('consumable')]));

    $both = $badges(auditorUser(['lab_id' => $lab->id]));
    expect($both)->toContain(__('nav.badge_approval', ['count' => 3]))
        ->toContain(__('nav.badge_managed_types', ['types' => $label('chemical').' + '.$label('consumable')]));

    $none = $badges(auditorUser(['lab_id' => $lab->id, 'itemTypes' => []]));
    expect($none)->toContain(__('nav.badge_approval', ['count' => 0]))->toContain(__('nav.badge_no_types'));
});

// --- BR-04: approving an overage issue ---------------------------------------------------------------

if (! function_exists('overageCase')) {
    /** An APPROVED requisition of the given categories with a stocked container, ready to issue 115 against 100 requested. */
    function overageCase(array $categories): array
    {
        $staff = staffUser();
        $g = Unit::where('code', 'g')->firstOrFail();
        $requisition = makeRequisition($staff, ['status' => 'APPROVED']);

        $lines = [];
        foreach (array_values($categories) as $i => $code) {
            $lines[] = $requisition->items()->create([
                'line_no' => $i + 1,
                'item_id' => makeItem(['category_id' => categoryId($code)])->id,
                'qty_requested' => '100.000000', 'unit_id' => $g->id, 'qty_requested_base' => '100.000000',
            ]);
        }
        $container = stockedContainer($lines[0]->item_id, '200.000000', $staff);

        return [$requisition->fresh(), $lines[0], $container, $staff, $g];
    }
}

test('BR-04: only a manager responsible for every kind of the requisition may approve an overage issue', function (array $held) {
    foreach (KIND_REQS as $name => [$categories, $kinds]) {
        [$requisition, $line, $container, $staff, $g] = overageCase($categories);
        $approver = labManagerUser(['lab_id' => $requisition->lab_id, 'itemTypes' => $held]);
        $issue = fn () => app(IssueService::class)->issue($line, $container, '115.000000', $g, scientistUser(), $staff, 'sig-hash', null, 'จ่ายทั้งขวด', $approver->id);

        if (kindCanDecide($held, $kinds)) {
            expect($issue()->qty_issued_base)->toBe('115.000000', $name);
            expect($line->fresh()->overage_approved_by)->toBe($approver->id);
        } else {
            expect($issue)->toThrow(ExcessiveIssueQuantityException::class);
            expect($line->fresh()->overage_approved_by)->toBeNull();
        }
    }
})->with('manager kinds');

test('BR-04: the wrong-kind refusal uses its own message', function () {
    [$requisition, $line, $container, $staff, $g] = overageCase(['MATERIAL']);
    $chemOnly = auditorUser(['lab_id' => $requisition->lab_id, 'itemTypes' => ['chemical']]);

    expect(fn () => app(IssueService::class)->issue($line, $container, '115.000000', $g, scientistUser(), $staff, 'sig-hash', null, 'จ่ายทั้งขวด', $chemOnly->id))
        ->toThrow(ExcessiveIssueQuantityException::class, __('requisitions.overage_approver_wrong_kind'));
});
