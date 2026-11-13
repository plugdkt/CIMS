<?php

use App\Domain\Loan\LoanCart;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
 * ITM-2610-070: the borrowing cart ("ตะกร้ายืม") of the loan module, built like the shop's basket. Session only, one
 * cart per user, for borrowing for oneself. Lines are checked by LoanService::assertCartLines() (own branch, ACTIVE,
 * whole quantity, not more than available); "ดำเนินการต่อ" opens the ordinary request form with the lines filled in,
 * and a request for oneself saved from it empties the cart. Counter staff (on behalf) and the DRAFT edit keep the
 * ordinary form.
 */

beforeEach(function () {
    Mail::fake();
});

if (! function_exists('loanCartLinesOf')) {
    /** @return array<string, int> */
    function loanCartLinesOf(User $user): array
    {
        return LoanCart::lines($user);
    }
}

// --- guests ---------------------------------------------------------------------------------------------------------

test('guests are sent to the login page by every cart route and nothing is put in a cart', function () {
    [, $asset] = loanBranch();

    $this->get(route('loans.cart'))->assertRedirect(route('login'));
    $this->post(route('loans.cart.add'), ['asset' => $asset->ulid, 'qty' => 1])->assertRedirect(route('login'));
    $this->put(route('loans.cart.update', $asset->ulid), ['qty' => 2])->assertRedirect(route('login'));
    $this->delete(route('loans.cart.remove', $asset->ulid))->assertRedirect(route('login'));
});

// --- add / update / remove ------------------------------------------------------------------------------------------

test('a borrower adds an asset of their own branch; adding it again adds to the same line; the cart page lists it with what is available now', function () {
    [$lab, $asset] = loanBranch(10);
    $staff = staffUser(['lab_id' => $lab->id]);

    $this->actingAs($staff)->from(route('loans.cart'))
        ->post(route('loans.cart.add'), ['asset' => $asset->ulid, 'qty' => 2])
        ->assertRedirect(route('loans.cart'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('loans.cart.added', ['item' => $asset->item->name_th]));
    expect(loanCartLinesOf($staff))->toBe([$asset->ulid => 2]);

    $this->actingAs($staff)->from(route('loans.cart'))->post(route('loans.cart.add'), ['asset' => $asset->ulid, 'qty' => 1])->assertSessionHasNoErrors();
    expect(loanCartLinesOf($staff))->toBe([$asset->ulid => 3]);

    $this->actingAs($staff)->get(route('loans.cart'))->assertOk()
        ->assertSee($asset->item->name_th)
        ->assertSee(route('loans.create', ['cart' => 1]), false)
        ->assertViewHas('lines', fn (array $lines) => count($lines) === 1 && $lines[0]['qty'] === 3 && $lines[0]['problem'] === null);

    // Nothing is reserved and no loan exists yet.
    expect(Loan::count())->toBe(0)->and($asset->fresh()->available())->toBe('10.000000');
});

test('the quantity must be a whole number of 1 or more, and not more than is available now', function () {
    [$lab, $asset] = loanBranch(10);
    $staff = staffUser(['lab_id' => $lab->id]);

    foreach ([0, -1, '1.5', 'abc', ''] as $bad) {
        $this->actingAs($staff)->post(route('loans.cart.add'), ['asset' => $asset->ulid, 'qty' => $bad])->assertSessionHasErrors('qty');
    }
    $this->actingAs($staff)->post(route('loans.cart.add'), ['asset' => $asset->ulid, 'qty' => 11])
        ->assertSessionHasErrors(['cart' => __('loans.error.not_enough_available', ['item' => $asset->item->name_th])]);

    expect(loanCartLinesOf($staff))->toBe([]);
});

test('a line is updated (re-checked against what is available) and removed; a line that is not in the cart answers 404 on update', function () {
    [$lab, $asset] = loanBranch(10);
    $staff = staffUser(['lab_id' => $lab->id]);
    $this->actingAs($staff)->post(route('loans.cart.add'), ['asset' => $asset->ulid, 'qty' => 1]);

    $this->actingAs($staff)->put(route('loans.cart.update', $asset->ulid), ['qty' => 5])
        ->assertRedirect(route('loans.cart'))->assertSessionHas('status', __('loans.cart.updated'));
    expect(loanCartLinesOf($staff))->toBe([$asset->ulid => 5]);

    $this->actingAs($staff)->put(route('loans.cart.update', $asset->ulid), ['qty' => 11])
        ->assertRedirect(route('loans.cart'))
        ->assertSessionHasErrors(['cart' => __('loans.error.not_enough_available', ['item' => $asset->item->name_th])]);
    expect(loanCartLinesOf($staff))->toBe([$asset->ulid => 5]);

    [, $notInCart] = loanBranch();
    $this->actingAs($staff)->put(route('loans.cart.update', $notInCart->ulid), ['qty' => 1])->assertNotFound();

    $this->actingAs($staff)->delete(route('loans.cart.remove', $asset->ulid))
        ->assertRedirect(route('loans.cart'))->assertSessionHas('status', __('loans.cart.removed'));
    expect(loanCartLinesOf($staff))->toBe([]);
    $this->actingAs($staff)->get(route('loans.cart'))->assertOk()->assertSee(__('loans.cart.empty'));
});

test('each user has their own cart', function () {
    [$lab, $asset] = loanBranch();
    $one = staffUser(['lab_id' => $lab->id]);
    $two = staffUser(['lab_id' => $lab->id]);

    $this->actingAs($one)->post(route('loans.cart.add'), ['asset' => $asset->ulid, 'qty' => 2])->assertSessionHasNoErrors();

    expect(loanCartLinesOf($one))->toBe([$asset->ulid => 2])->and(loanCartLinesOf($two))->toBe([]);
    $this->actingAs($two)->get(route('loans.cart'))->assertOk()->assertViewHas('lines', []);
});

// --- what may go in ---------------------------------------------------------------------------------------------------

test('an asset of another branch, a retired asset and an unknown ULID are refused and never reach the cart', function () {
    [$lab, $asset] = loanBranch();
    [, $otherBranch] = loanBranch();
    $staff = staffUser(['lab_id' => $lab->id]);

    $this->actingAs($staff)->post(route('loans.cart.add'), ['asset' => $otherBranch->ulid, 'qty' => 1])
        ->assertSessionHasErrors(['cart' => __('loans.error.asset_other_lab')]);

    DB::table('loan_assets')->where('id', $asset->id)->update(['status' => 'RETIRED']);
    $this->actingAs($staff)->post(route('loans.cart.add'), ['asset' => $asset->ulid, 'qty' => 1])
        ->assertSessionHasErrors(['cart' => __('loans.error.asset_not_lendable')]);

    $this->actingAs($staff)->post(route('loans.cart.add'), ['asset' => str_repeat('Z', 26), 'qty' => 1])->assertSessionHasErrors('asset');

    expect(loanCartLinesOf($staff))->toBe([]);
});

test('a line whose asset was retired after it went in is flagged on the cart page and can still be removed', function () {
    [$lab, $asset] = loanBranch();
    $staff = staffUser(['lab_id' => $lab->id]);
    $this->actingAs($staff)->post(route('loans.cart.add'), ['asset' => $asset->ulid, 'qty' => 1])->assertSessionHasNoErrors();

    DB::table('loan_assets')->where('id', $asset->id)->update(['status' => 'RETIRED']);

    $this->actingAs($staff)->get(route('loans.cart'))->assertOk()->assertSee(__('loans.error.asset_not_lendable'));
    $this->actingAs($staff)->delete(route('loans.cart.remove', $asset->ulid))->assertRedirect(route('loans.cart'));
    expect(loanCartLinesOf($staff))->toBe([]);
});

test('a user with no branch has no cart (sent to the "waiting for a branch" page); a user without loan.create gets 403', function () {
    [, $asset] = loanBranch();
    $noBranch = staffUser(['lab_id' => null]);

    $this->actingAs($noBranch)->get(route('loans.cart'))->assertRedirect(route('loans.pending-lab'));
    $this->actingAs($noBranch)->post(route('loans.cart.add'), ['asset' => $asset->ulid, 'qty' => 1])->assertRedirect(route('loans.pending-lab'));
    expect(loanCartLinesOf($noBranch))->toBe([]);

    $nocard = navUser('nocard');
    $this->actingAs($nocard)->get(route('loans.cart'))->assertForbidden();
    $this->actingAs($nocard)->post(route('loans.cart.add'), ['asset' => $asset->ulid, 'qty' => 1])->assertForbidden();
    $this->actingAs($nocard)->delete(route('loans.cart.remove', $asset->ulid))->assertForbidden();
    $this->actingAs($nocard)->put(route('loans.cart.update', $asset->ulid), ['qty' => 1])->assertForbidden();
});

test('the "available to lend" page offers "add to cart" on the own branch\'s assets only, with the cart button and its count', function () {
    [$lab, $asset] = loanBranch();
    [, $otherBranch] = loanBranch();
    $scientist = scientistUser(['lab_id' => $lab->id, 'person_type' => 'STAFF', 'profile_completed_at' => now()]);

    $this->actingAs($scientist)->get(route('loans.available'))->assertOk()
        ->assertSee(route('loans.cart.add'), false)
        ->assertSee(route('loans.cart'), false)
        ->assertSee('value="'.$asset->ulid.'"', false)
        ->assertViewHas('cartCount', 0);

    $this->actingAs($scientist)->post(route('loans.cart.add'), ['asset' => $asset->ulid, 'qty' => 1])->assertSessionHasNoErrors();
    $this->actingAs($scientist)->get(route('loans.available'))->assertViewHas('cartCount', 1);

    // ADMIN of one branch looking at another branch: the list is shown, but nothing of it can go in the cart.
    $admin = adminUser(['lab_id' => $lab->id, 'person_type' => 'STAFF']);
    $this->actingAs($admin)->get(route('loans.available', ['lab_id' => $otherBranch->lab_id]))->assertOk()
        ->assertDontSee('value="'.$otherBranch->ulid.'"', false);
});

// --- "ดำเนินการต่อ": the request form ----------------------------------------------------------------------------------

test('the request form opened from the cart has the cart\'s lines filled in; without ?cart=1 it is the ordinary empty form', function () {
    [$lab, $asset] = loanBranch(10);
    $other = loanExtraAsset($lab, 5);
    $staff = staffUser(['lab_id' => $lab->id]);
    $this->actingAs($staff)->post(route('loans.cart.add'), ['asset' => $asset->ulid, 'qty' => 2]);
    $this->actingAs($staff)->post(route('loans.cart.add'), ['asset' => $other->ulid, 'qty' => 1]);

    $this->actingAs($staff)->get(route('loans.create', ['cart' => 1]))->assertOk()
        ->assertViewHas('fromCart', true)
        ->assertViewHas('existing', [$asset->ulid => '2', $other->ulid => '1'])
        ->assertSee('name="from_cart" value="1"', false)
        ->assertSee(__('loans.cart.form_note'));

    // A search on the form that leaves a cart line out still lists that line, so it is sent with the form.
    $this->actingAs($staff)->get(route('loans.create', ['cart' => 1, 'q' => 'ไม่มีของชื่อนี้แน่นอน']))->assertOk()
        ->assertViewHas('assets', fn ($assets) => $assets->pluck('ulid')->sort()->values()->all() === collect([$asset->ulid, $other->ulid])->sort()->values()->all());

    $this->actingAs($staff)->get(route('loans.create'))->assertOk()
        ->assertViewHas('fromCart', false)
        ->assertViewHas('existing', [])
        ->assertDontSee('name="from_cart"', false);
});

test('saving a request for oneself from the cart empties the cart; the rules of LoanService still apply and a refused save keeps it', function () {
    [$lab, $asset] = loanBranch(10);
    $staff = staffUser(['lab_id' => $lab->id]);
    $this->actingAs($staff)->post(route('loans.cart.add'), ['asset' => $asset->ulid, 'qty' => 2]);

    // Refused by LoanService (pick-up date in the past): nothing saved, the cart is kept.
    $this->actingAs($staff)->post(route('loans.store'), loanPayload([$asset->ulid => '2'], ['from_cart' => '1', 'loan_date' => now()->subDay()->format('Y-m-d')]))
        ->assertSessionHasErrors('loan');
    expect(Loan::count())->toBe(0)->and(loanCartLinesOf($staff))->toBe([$asset->ulid => 2]);

    $this->actingAs($staff)->post(route('loans.store'), loanPayload([$asset->ulid => '2'], ['from_cart' => '1', 'action' => 'submit']))
        ->assertSessionHasNoErrors();

    $loan = Loan::with('lines')->firstOrFail();
    expect($loan->borrower_id)->toBe($staff->id)
        ->and($loan->created_by)->toBe($staff->id)
        ->and($loan->status)->toBe('SUBMITTED')
        ->and($loan->lines)->toHaveCount(1)
        ->and((string) $loan->lines->first()->qty)->toBe('2.000000')
        ->and(loanCartLinesOf($staff))->toBe([]);
});

test('a request saved from the ordinary form (no from_cart) leaves the cart alone', function () {
    [$lab, $asset] = loanBranch(10);
    $staff = staffUser(['lab_id' => $lab->id]);
    $this->actingAs($staff)->post(route('loans.cart.add'), ['asset' => $asset->ulid, 'qty' => 2]);

    $this->actingAs($staff)->post(route('loans.store'), loanPayload([$asset->ulid => '1']))->assertSessionHasNoErrors();

    expect(Loan::count())->toBe(1)->and(loanCartLinesOf($staff))->toBe([$asset->ulid => 2]);
});

// --- what the cart does not touch -----------------------------------------------------------------------------------

test('counter staff borrowing on behalf of someone get the ordinary form (no cart lines) and their own cart is not emptied', function () {
    [$lab, $asset] = loanBranch(10);
    $counter = scientistUser(['lab_id' => $lab->id, 'person_type' => 'STAFF', 'profile_completed_at' => now()]);
    $borrower = staffUser(['lab_id' => $lab->id]);
    $this->actingAs($counter)->post(route('loans.cart.add'), ['asset' => $asset->ulid, 'qty' => 3])->assertSessionHasNoErrors();

    $this->actingAs($counter)->get(route('loans.create', ['borrower' => $borrower->ulid, 'cart' => 1]))->assertOk()
        ->assertViewHas('onBehalf', true)
        ->assertViewHas('fromCart', false)
        ->assertViewHas('existing', [])
        ->assertDontSee('name="from_cart"', false);

    // Even if `from_cart` is sent, an on-behalf request leaves the counter's own cart alone.
    $this->actingAs($counter)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], ['borrower' => $borrower->ulid, 'from_cart' => '1']))
        ->assertSessionHasNoErrors();

    $loan = Loan::firstOrFail();
    expect($loan->borrower_id)->toBe($borrower->id)
        ->and($loan->created_by)->toBe($counter->id)
        ->and(loanCartLinesOf($counter))->toBe([$asset->ulid => 3]);
});

test('editing an existing DRAFT keeps the ordinary form: the cart is not filled in', function () {
    [$lab, $asset] = loanBranch(10);
    $other = loanExtraAsset($lab, 5);
    $staff = staffUser(['lab_id' => $lab->id]);
    $draft = draftLoan($staff, $asset, 2);
    $this->actingAs($staff)->post(route('loans.cart.add'), ['asset' => $other->ulid, 'qty' => 4])->assertSessionHasNoErrors();

    $this->actingAs($staff)->get(route('loans.edit', ['loan' => $draft, 'cart' => 1]))->assertOk()
        ->assertViewHas('fromCart', false)
        ->assertViewHas('existing', [$asset->ulid => '2'])
        ->assertDontSee('name="from_cart"', false);
});

test('the loan card on the home page shows the cart\'s size like the shop\'s basket badge', function () {
    [$lab, $asset] = loanBranch();
    $staff = staffUser(['lab_id' => $lab->id]);
    $badge = (string) __('nav.badge_cart', ['count' => 1]);

    $this->actingAs($staff)->get('/')->assertOk()->assertDontSee($badge);
    $this->actingAs($staff)->post(route('loans.cart.add'), ['asset' => $asset->ulid, 'qty' => 2])->assertSessionHasNoErrors();
    $this->actingAs($staff)->get('/')->assertOk()->assertSee($badge);
});

// --- borrowers on the "available to lend" page (CEO 2026-11-13: "เปิดให้ผู้ยืมเห็น") -----------------------------------

test('a student, a staff member and an advisor open the "available" page: own branch only, a ?lab_id= cannot widen it, and no price is shown', function () {
    [$labA, $managerA] = loanManagers();
    [$labB, $managerB] = loanManagers();
    loanService()->register($managerA, ['lab_id' => $labA->id, 'item_id' => makeCountItem(['name_th' => 'ZBORROWA'])->id, 'tracking_mode' => 'POOL', 'qty' => 4, 'replacement_value' => '98765.40']);
    loanService()->register($managerB, ['lab_id' => $labB->id, 'item_id' => makeCountItem(['name_th' => 'ZBORROWB'])->id, 'tracking_mode' => 'POOL', 'qty' => 4, 'replacement_value' => '55555.10']);

    foreach ([studentUser(['lab_id' => $labA->id]), staffUser(['lab_id' => $labA->id]), navUser('advisor', ['lab_id' => $labA->id])] as $user) {
        foreach ([null, $labB->id, 999999, 'abc'] as $labParam) {
            $this->actingAs($user)->get(route('loans.available', $labParam === null ? [] : ['lab_id' => $labParam]))
                ->assertOk()
                ->assertSee('ZBORROWA')
                ->assertDontSee('ZBORROWB')
                ->assertDontSee('98765')
                ->assertDontSee('55555')
                ->assertSee(__('loans.col_on_loan'))
                ->assertSee(__('loans.col_unavailable'))
                ->assertSee(__('loans.cart.btn_add'))
                ->assertViewHas('isAdmin', false)
                ->assertViewHas('assets', fn ($page) => collect($page->items())->every(fn ($a) => $a->lab_id === $labA->id));
        }
    }
});

test('a borrower with no branch is sent from the "available" page to the "no branch yet" page; a user with no loan permission gets 403', function () {
    $this->actingAs(studentUser(['lab_id' => null]))->get(route('loans.available'))->assertRedirect(route('loans.pending-lab'));
    $this->actingAs(staffUser(['lab_id' => null]))->get(route('loans.available'))->assertRedirect(route('loans.pending-lab'));
    $this->actingAs(navUser('nocard'))->get(route('loans.available'))->assertForbidden();
});

test('a student finds the asset on the "available" page, puts it in the cart and saves a request from it', function () {
    [$lab, $asset] = loanBranch(10);
    $student = studentUser(['lab_id' => $lab->id]);

    $this->actingAs($student)->get(route('loans.available'))->assertOk()->assertSee('value="'.$asset->ulid.'"', false);
    $this->actingAs($student)->from(route('loans.available'))
        ->post(route('loans.cart.add'), ['asset' => $asset->ulid, 'qty' => 2])
        ->assertRedirect(route('loans.available'))->assertSessionHasNoErrors();

    $this->actingAs($student)->get(route('loans.cart'))->assertOk()->assertSee($asset->item->name_th);
    $this->actingAs($student)->get(route('loans.create', ['cart' => 1]))->assertOk()->assertViewHas('existing', [$asset->ulid => '2']);

    $this->actingAs($student)->post(route('loans.store'), loanPayload([$asset->ulid => '2'], ['from_cart' => '1']))->assertSessionHasNoErrors();

    $loan = Loan::with('lines')->firstOrFail();
    expect($loan->borrower_id)->toBe($student->id)
        ->and($loan->lab_id)->toBe($lab->id)
        ->and($loan->status)->toBe('DRAFT')
        ->and((string) $loan->lines->first()->qty)->toBe('2.000000')
        ->and(loanCartLinesOf($student))->toBe([]);
});

// --- after QA (ITM-2610-070) -------------------------------------------------------------------------------------------

test('a user who moved to another branch sees nothing of an old-branch asset left in the cart (only a generic label), and can still remove it', function () {
    [$labA, $asset] = loanBranch(10);
    $labB = makeLab();
    $staff = staffUser(['lab_id' => $labA->id]);
    // Put straight in the cart (a POST would leave the "added <name>" flash message for the next page).
    LoanCart::put($staff, [$asset->ulid => 2]);
    $location = $asset->location?->name;

    $staff->update(['lab_id' => $labB->id]);
    $staff = $staff->fresh();

    $response = $this->actingAs($staff)->get(route('loans.cart'))->assertOk()
        ->assertSee(__('loans.cart.asset_gone'))
        ->assertSee(__('loans.error.asset_other_lab'))
        ->assertDontSee($asset->item->name_th)
        ->assertDontSee($asset->item->item_code)
        // No quantity box (update and remove share the URL; only "นำออก" is offered).
        ->assertDontSee('name="qty"', false)
        ->assertSee(route('loans.cart.remove', $asset->ulid), false)
        ->assertViewHas('lines', fn (array $lines) => $lines[0]['asset'] === null && $lines[0]['problem'] === __('loans.error.asset_other_lab'));
    if ($asset->asset_tag) {
        $response->assertDontSee($asset->asset_tag);
    }
    if ($location) {
        $response->assertDontSee($location);
    }

    // The form opened from the cart does not fill it in either.
    $this->actingAs($staff)->get(route('loans.create', ['cart' => 1]))->assertOk()->assertViewHas('existing', []);

    $this->actingAs($staff)->delete(route('loans.cart.remove', $asset->ulid))->assertRedirect(route('loans.cart'));
    expect(loanCartLinesOf($staff))->toBe([]);
});

test('adding the same asset again and again past 9 digits ends in "not enough available", never in the whole-number error', function () {
    [$lab, $asset] = loanBranch(10);
    $staff = staffUser(['lab_id' => $lab->id]);
    LoanCart::put($staff, [$asset->ulid => 5]);

    $this->actingAs($staff)->post(route('loans.cart.add'), ['asset' => $asset->ulid, 'qty' => 999999999])
        ->assertSessionHasErrors(['cart' => __('loans.error.not_enough_available', ['item' => $asset->item->name_th])]);

    expect(loanCartLinesOf($staff))->toBe([$asset->ulid => 5]);
});
