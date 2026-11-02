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

    // A manager cannot dispense, so only the approval badge exists on the warehouse card.
    expect($badges[__('nav.card_ops')])->toBe([__('nav.badge_approval', ['count' => 2])]);

    // The other branch's manager sees only their own two.
    $auditorB = auditorUser(['lab_id' => $labB->id]);
    $badgesB = badgesOf($this->actingAs($auditorB)->get('/')->assertOk()->getContent());
    expect($badgesB[__('nav.card_ops')])->toBe([__('nav.badge_approval', ['count' => 2])]);
});

test('the dispense badge counts own-branch APPROVED and PARTIALLY_ISSUED only, and a dispenser gets no approval badge', function () {
    $labA = makeLab();
    $labB = makeLab();
    $scientist = scientistUser(['lab_id' => $labA->id]);
    $studentA = studentUser(['lab_id' => $labA->id]);
    $studentB = studentUser(['lab_id' => $labB->id]);

    makeRequisition($studentA, ['lab_id' => $labA->id, 'status' => 'APPROVED']);
    makeRequisition($studentA, ['lab_id' => $labA->id, 'status' => 'APPROVED']);
    makeRequisition($studentA, ['lab_id' => $labA->id, 'status' => 'PARTIALLY_ISSUED']);
    makeRequisition($studentA, ['lab_id' => $labA->id, 'status' => 'ISSUED']);              // done: no
    makeRequisition($studentA, ['lab_id' => $labA->id, 'status' => 'ADVISOR_APPROVED']);    // not approved yet: no
    makeRequisition($studentB, ['lab_id' => $labB->id, 'status' => 'APPROVED']);            // other branch: no

    $badges = badgesOf($this->actingAs($scientist)->get('/')->assertOk()->getContent());

    expect($badges[__('nav.card_ops')])->toBe([__('nav.badge_dispense', ['count' => 3])]);
});

test('a dispenser with no branch assigned gets no dispense badge rather than a misleading zero', function () {
    $scientist = scientistUser(['lab_id' => null]);

    $badges = badgesOf($this->actingAs($scientist)->get('/')->assertOk()->getContent());

    expect($badges[__('nav.card_ops')])->toBe([]);
});

test('a user who both approves and dispenses (AUDITOR + SCIENTIST) sees both badges on the warehouse card, approval first', function () {
    $lab = makeLab();
    $user = auditorUser(['lab_id' => $lab->id]);
    $user->roles()->attach(\App\Models\Role::where('code', 'SCIENTIST')->firstOrFail());
    $student = studentUser(['lab_id' => $lab->id]);

    makeRequisition($student, ['lab_id' => $lab->id, 'status' => 'ADVISOR_APPROVED']);
    makeRequisition($student, ['lab_id' => $lab->id, 'status' => 'APPROVED']);
    makeRequisition($student, ['lab_id' => $lab->id, 'status' => 'APPROVED']);

    $badges = badgesOf($this->actingAs($user->fresh())->get('/')->assertOk()->getContent());

    expect($badges[__('nav.card_ops')])->toBe([
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

test('phase 1: the cart is still one shared basket, so both requesting cards show the same cart count (changes in phase 2)', function () {
    $staff = staffUser(['lab_id' => makeLab()->id]);
    $cart = [
        '01JTESTCARTITEM0000000001' => ['qty' => '1.000000', 'unit_id' => 1],
        '01JTESTCARTITEM0000000002' => ['qty' => '2.000000', 'unit_id' => 1],
    ];

    $badges = badgesOf($this->withSession(['shop.cart' => $cart])->actingAs($staff)->get('/')->assertOk()->getContent());

    expect($badges[__('nav.card_chem')])->toContain(__('nav.badge_cart', ['count' => 2]));
    expect($badges[__('nav.card_supply')])->toContain(__('nav.badge_cart', ['count' => 2]));

    // An empty cart shows no cart badge at all, only the "my open" one.
    $this->flushSession();
    $empty = badgesOf($this->actingAs($staff)->get('/')->assertOk()->getContent());
    expect($empty[__('nav.card_chem')])->toBe([__('nav.badge_my_open', ['count' => 0])]);
});
