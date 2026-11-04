<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * ITM-2610-052 phase 1 (plan 2.2): the numbers on the home cards must equal the real queues,
 * stay inside the viewer's branch, and never show a count the viewer cannot act on.
 *
 * Requesting cards: "my open requisitions" is judged by the lines' real categories (a mixed
 * legacy requisition counts on both cards), not by the request_type box the requester ticked.
 * Warehouse card: "waiting for approval" and "waiting to be issued" are two separate numbers;
 * a viewer who cannot decide (ADMIN, a dispenser) or cannot dispense gets no such badge (null,
 * not a misleading zero).
 */

if (! function_exists('badgesOf')) {
    /** @return array<string, list<string>> card title => badge texts */
    function badgesOf(string $html): array
    {
        return collect(homeCards($html))->mapWithKeys(fn (array $c) => [$c['title'] => $c['badges']])->all();
    }
}

test('"my open requisitions" counts per kind from the lines, counts mixed ones on both cards, and skips finished and other people\'s', function () {
    $student = studentUser();
    $other = studentUser();

    requisitionWithLines($student, ['CHEMICAL'], ['status' => 'DRAFT']);                       // chem
    requisitionWithLines($student, ['CHEMICAL', 'GLASSWARE'], ['status' => 'SUBMITTED']);      // mixed: both
    requisitionWithLines($student, ['MATERIAL'], ['status' => 'APPROVED']);                    // supply
    requisitionWithLines($student, ['CONSUMABLE'], ['status' => 'PARTIALLY_ISSUED']);          // supply
    requisitionWithLines($student, ['CHEMICAL'], ['status' => 'ISSUED']);                      // finished: no
    requisitionWithLines($student, ['GLASSWARE'], ['status' => 'REJECTED']);                   // finished: no
    requisitionWithLines($student, ['DYE'], ['status' => 'CANCELLED']);                        // finished: no
    requisitionWithLines($other, ['CHEMICAL'], ['status' => 'SUBMITTED']);                     // someone else's: no
    makeRequisition($student, ['status' => 'SUBMITTED']);                                      // no lines at all: on neither card

    $badges = badgesOf($this->actingAs($student)->get('/')->assertOk()->getContent());

    expect($badges[__('nav.card_chem')])->toBe([__('nav.badge_my_open', ['count' => 2])]);     // DRAFT chem + mixed
    expect($badges[__('nav.card_supply')])->toBe([__('nav.badge_my_open', ['count' => 3])]);   // mixed + MATERIAL + CONSUMABLE
});

test('"declared" request_type is not trusted: a requisition ticked CHEMICAL whose lines are all consumable counts on the consumable card only', function () {
    $staff = staffUser();

    requisitionWithLines($staff, ['CONSUMABLE'], ['status' => 'SUBMITTED', 'request_type' => ['CHEMICAL']]);

    $badges = badgesOf($this->actingAs($staff)->get('/')->assertOk()->getContent());

    expect($badges[__('nav.card_chem')])->toBe([__('nav.badge_my_open', ['count' => 0])]);
    expect($badges[__('nav.card_supply')])->toBe([__('nav.badge_my_open', ['count' => 1])]);
});

test('an ADVISOR sees "waiting on you" per kind for their advisees\' SUBMITTED requisitions only; a non-advisor never does', function () {
    $student = studentUser();
    $advisor = \App\Models\User::findOrFail($student->advisor_id);

    requisitionWithLines($student, ['CHEMICAL'], ['status' => 'SUBMITTED']);
    requisitionWithLines($student, ['GLASSWARE'], ['status' => 'SUBMITTED']);
    requisitionWithLines($student, ['MATERIAL'], ['status' => 'SUBMITTED']);
    requisitionWithLines($student, ['CHEMICAL'], ['status' => 'ADVISOR_APPROVED']);    // already decided by the advisor
    requisitionWithLines($student, ['CHEMICAL'], ['status' => 'DRAFT']);               // not submitted yet

    $badges = badgesOf($this->actingAs($advisor)->get('/')->assertOk()->getContent());
    expect($badges[__('nav.card_chem')])->toContain(__('nav.badge_advisee_waiting', ['count' => 1]));
    expect($badges[__('nav.card_supply')])->toContain(__('nav.badge_advisee_waiting', ['count' => 2]));

    // The student themself holds no approve_advisor, so they get only their own "my open" badge
    // (chemical: 3 not finished; consumable: GLASSWARE + MATERIAL = 2) and never "waiting on you".
    $own = badgesOf($this->actingAs($student)->get('/')->assertOk()->getContent());
    expect($own[__('nav.card_chem')])->toBe([__('nav.badge_my_open', ['count' => 3])]);
    expect($own[__('nav.card_supply')])->toBe([__('nav.badge_my_open', ['count' => 2])]);
});

test('the approval badge counts the branch queue only: ADVISOR_APPROVED plus non-student SUBMITTED, in the manager\'s own branch', function () {
    $labA = makeLab();
    $labB = makeLab();
    $auditor = auditorUser(['lab_id' => $labA->id]);
    $studentA = studentUser(['lab_id' => $labA->id]);
    $staffA = staffUser(['lab_id' => $labA->id]);
    $studentB = studentUser(['lab_id' => $labB->id]);
    $staffB = staffUser(['lab_id' => $labB->id]);

    makeRequisition($studentA, ['lab_id' => $labA->id, 'status' => 'ADVISOR_APPROVED']);   // counts
    makeRequisition($staffA, ['lab_id' => $labA->id, 'status' => 'SUBMITTED']);            // counts: a non-student skips the advisor
    makeRequisition($studentA, ['lab_id' => $labA->id, 'status' => 'SUBMITTED']);          // still waiting on the advisor: no
    makeRequisition($studentA, ['lab_id' => $labA->id, 'status' => 'APPROVED']);           // already approved: not this queue
    makeRequisition($studentB, ['lab_id' => $labB->id, 'status' => 'ADVISOR_APPROVED']);   // other branch: no
    makeRequisition($staffB, ['lab_id' => $labB->id, 'status' => 'SUBMITTED']);            // other branch: no

    $badges = badgesOf($this->actingAs($auditor)->get('/')->assertOk()->getContent());

    // approvals.md K: a manager approves AND dispenses now: kinds they hold, the approval badge, then the dispense badge
    // (one APPROVED requisition in branch A, none in B).
    expect($badges[__('nav.card_ops')])->toBe([managedBadge(['chemical', 'consumable']), __('nav.badge_approval', ['count' => 2]), __('nav.badge_dispense', ['count' => 1])]);

    // The other branch's manager sees only their own two.
    $auditorB = auditorUser(['lab_id' => $labB->id]);
    $badgesB = badgesOf($this->actingAs($auditorB)->get('/')->assertOk()->getContent());
    expect($badgesB[__('nav.card_ops')])->toBe([managedBadge(['chemical', 'consumable']), __('nav.badge_approval', ['count' => 2]), __('nav.badge_dispense', ['count' => 0])]);
});

test('the dispense badge counts own-branch APPROVED and PARTIALLY_ISSUED only; a scientist (approves and dispenses, approvals.md K) also gets the own-branch approval badge', function () {
    $labA = makeLab();
    $labB = makeLab();
    $scientist = scientistUser(['lab_id' => $labA->id]);
    $studentA = studentUser(['lab_id' => $labA->id]);
    $studentB = studentUser(['lab_id' => $labB->id]);

    makeRequisition($studentA, ['lab_id' => $labA->id, 'status' => 'APPROVED']);
    makeRequisition($studentA, ['lab_id' => $labA->id, 'status' => 'APPROVED']);
    makeRequisition($studentA, ['lab_id' => $labA->id, 'status' => 'PARTIALLY_ISSUED']);
    makeRequisition($studentA, ['lab_id' => $labA->id, 'status' => 'ISSUED']);              // done: no
    makeRequisition($studentA, ['lab_id' => $labA->id, 'status' => 'ADVISOR_APPROVED']);    // not approved yet: not dispensable, but it IS in the scientist's approval queue
    makeRequisition($studentB, ['lab_id' => $labB->id, 'status' => 'APPROVED']);            // other branch: no
    makeRequisition($studentB, ['lab_id' => $labB->id, 'status' => 'ADVISOR_APPROVED']);    // other branch: not in this scientist's queue either

    $badges = badgesOf($this->actingAs($scientist)->get('/')->assertOk()->getContent());

    // No "kinds held" badge: a scientist is not a branch manager and is not narrowed by kind.
    expect($badges[__('nav.card_ops')])->toBe([__('nav.badge_approval', ['count' => 1]), __('nav.badge_dispense', ['count' => 3])]);
});

test('a scientist with no branch assigned gets neither a dispense nor an approval badge (null hides it; no misleading zero, no leaked count)', function () {
    $labA = makeLab();
    makeRequisition(studentUser(['lab_id' => $labA->id]), ['lab_id' => $labA->id, 'status' => 'ADVISOR_APPROVED']);
    makeRequisition(studentUser(['lab_id' => $labA->id]), ['lab_id' => $labA->id, 'status' => 'APPROVED']);
    $scientist = scientistUser(['lab_id' => null]);

    $badges = badgesOf($this->actingAs($scientist)->get('/')->assertOk()->getContent());

    expect($badges[__('nav.card_ops')])->toBe([]);
});

test('a user who both approves and dispenses (AUDITOR + SCIENTIST) sees both badges on the warehouse card (kinds held, then approval, then dispense)', function () {
    $lab = makeLab();
    $user = auditorUser(['lab_id' => $lab->id]);
    $user->roles()->attach(\App\Models\Role::where('code', 'SCIENTIST')->firstOrFail());
    $student = studentUser(['lab_id' => $lab->id]);

    makeRequisition($student, ['lab_id' => $lab->id, 'status' => 'ADVISOR_APPROVED']);
    makeRequisition($student, ['lab_id' => $lab->id, 'status' => 'APPROVED']);
    makeRequisition($student, ['lab_id' => $lab->id, 'status' => 'APPROVED']);

    $badges = badgesOf($this->actingAs($user->fresh())->get('/')->assertOk()->getContent());

    expect($badges[__('nav.card_ops')])->toBe([
        managedBadge(['chemical', 'consumable']),
        __('nav.badge_approval', ['count' => 1]),
        __('nav.badge_dispense', ['count' => 2]),
    ]);
});

test('ADMIN sees every branch\'s work in the overview numbers it is allowed, but no badge on any card (ADMIN approves and dispenses nothing)', function () {
    $admin = adminUser(['lab_id' => makeLab()->id]);
    $labA = makeLab();
    $studentA = studentUser(['lab_id' => $labA->id]);
    makeRequisition($studentA, ['lab_id' => $labA->id, 'status' => 'ADVISOR_APPROVED']);
    makeRequisition($studentA, ['lab_id' => $labA->id, 'status' => 'APPROVED']);

    $badges = badgesOf($this->actingAs($admin)->get('/')->assertOk()->getContent());
    expect($badges[__('nav.card_ops')])->toBe([]);
    expect($badges[__('nav.card_adm')])->toBe([]);

    $overview = $this->actingAs($admin)->get(route('warehouse.overview'))->assertOk();
    expect($overview->viewData('approvalCount'))->toBeNull();
    expect($overview->viewData('dispenseCount'))->toBeNull();
});

test('phase 2: each requesting card shows the count of its own basket only', function () {
    $staff = staffUser(['lab_id' => makeLab()->id]);
    $line = ['qty' => '1.000000', 'unit_id' => 1];

    $badges = badgesOf($this->withSession([
        'shop.cart.chemical' => ['01JTESTCARTITEM0000000001' => $line, '01JTESTCARTITEM0000000002' => $line],
        'shop.cart.consumable' => ['01JTESTCARTITEM0000000003' => $line],
    ])->actingAs($staff)->get('/')->assertOk()->getContent());

    expect($badges[__('nav.card_chem')])->toContain(__('nav.badge_cart', ['count' => 2]));
    expect($badges[__('nav.card_supply')])->toContain(__('nav.badge_cart', ['count' => 1]));

    // Only one basket filled: the other card shows no cart badge.
    $this->flushSession();
    $onlySupply = badgesOf($this->withSession(['shop.cart.consumable' => ['01JTESTCARTITEM0000000003' => $line]])
        ->actingAs($staff)->get('/')->assertOk()->getContent());
    expect($onlySupply[__('nav.card_chem')])->toBe([__('nav.badge_my_open', ['count' => 0])]);
    expect($onlySupply[__('nav.card_supply')])->toContain(__('nav.badge_cart', ['count' => 1]));

    // The old single `shop.cart` key is no longer read.
    $this->flushSession();
    $old = badgesOf($this->withSession(['shop.cart' => ['01JTESTCARTITEM0000000004' => $line]])
        ->actingAs($staff)->get('/')->assertOk()->getContent());
    expect($old[__('nav.card_chem')])->toBe([__('nav.badge_my_open', ['count' => 0])]);
    expect($old[__('nav.card_supply')])->toBe([__('nav.badge_my_open', ['count' => 0])]);

    // An empty cart shows no cart badge at all, only the "my open" one.
    $this->flushSession();
    $empty = badgesOf($this->actingAs($staff)->get('/')->assertOk()->getContent());
    expect($empty[__('nav.card_chem')])->toBe([__('nav.badge_my_open', ['count' => 0])]);
});

test('a single-kind manager sees only their own kind in the "ดูแล" badge, and a manager with no kinds sees the warning instead', function () {
    $lab = makeLab();

    $chem = badgesOf($this->actingAs(auditorUser(['lab_id' => $lab->id, 'itemTypes' => ['chemical']]))->get('/')->assertOk()->getContent());
    expect($chem[__('nav.card_ops')])->toBe([managedBadge(['chemical']), __('nav.badge_approval', ['count' => 0]), __('nav.badge_dispense', ['count' => 0])]);
    expect($chem[__('nav.card_ops')][0])->not->toContain(__('requisitions.request_type_consumable'));

    $supply = badgesOf($this->actingAs(labManagerUser(['lab_id' => $lab->id, 'itemTypes' => ['consumable']]))->get('/')->assertOk()->getContent());
    expect($supply[__('nav.card_ops')])->toBe([managedBadge(['consumable']), __('nav.badge_approval', ['count' => 0]), __('nav.badge_dispense', ['count' => 0])]);
    expect($supply[__('nav.card_ops')][0])->not->toContain(__('requisitions.request_type_chemical'));

    $none = badgesOf($this->actingAs(auditorUser(['lab_id' => $lab->id, 'itemTypes' => []]))->get('/')->assertOk()->getContent());
    expect($none[__('nav.card_ops')])->toBe([__('nav.badge_no_types'), __('nav.badge_approval', ['count' => 0]), __('nav.badge_dispense', ['count' => 0])]);
});
