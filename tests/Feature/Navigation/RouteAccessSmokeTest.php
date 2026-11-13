<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * ITM-2610-052 phase 1 (plan 1.1 point 4, 6.1): hiding a card or a tab is not protection, and
 * moving the menu must not move any page's own authorization. Every important page keeps the
 * same answer it gave before the sidebar was replaced: 200 for the roles that may open it, 403
 * for everyone else, asked directly by URL (not by looking for a link). Lesson from T-040: a
 * test that only follows menu links never notices a page whose gate drifted.
 *
 * Roles: student (+advisor/staff behave alike), scientist (dispenser), auditor (= lab manager),
 * admin, and "nocard" (a role holding only audit.view: opens nothing in the app proper).
 * Expected answers come from PermissionSeeder + the Policies each page enforces; any change
 * here means a permission changed, which is not part of this task.
 */

$open = 200;
$deny = 403;

dataset('direct page access', function () use ($open, $deny) {
    // route name => [student, scientist, auditor, admin, nocard]
    $matrix = [
        'items.index' => [$open, $open, $open, $open, $deny],
        'chemicals.lookup' => [$open, $open, $open, $open, $deny],
        'stock-in.index' => [$open, $open, $open, $open, $deny],
        'ims.lots.index' => [$open, $open, $open, $open, $deny],
        'locations.index' => [$deny, $deny, $open, $deny, $deny],
        'stock-takes.index' => [$deny, $deny, $open, $open, $deny],
        'disposals.index' => [$deny, $deny, $open, $open, $deny],
        'adjustments.index' => [$deny, $deny, $open, $open, $deny],
        'reports.index' => [$deny, $deny, $open, $open, $deny],
        'labs.members' => [$deny, $deny, $open, $deny, $deny],
        'admin.users.index' => [$deny, $deny, $deny, $open, $deny],
        'admin.labs.index' => [$deny, $deny, $deny, $open, $deny],
        'requisitions.index' => [$open, $open, $open, $open, $deny],
        'requisitions.manage' => [$deny, $open, $open, $open, $deny],
        'warehouse.overview' => [$deny, $open, $open, $open, $deny],
        'shop.index' => [$open, $open, $open, $open, $deny],
        // ITM-2610-070 (CEO 2026-11-13 "เปิดให้ผู้ยืมเห็น"): borrowers open it too, to fill the borrowing cart.
        'loans.available' => [$open, $open, $open, $open, $deny],
        // ITM-2610-070: the borrowing cart (every borrower; nocard has no loan permission).
        'loans.cart' => [$open, $open, $open, $open, $deny],
        'loans.assets.index' => [$deny, $deny, $open, $open, $deny],
        'loans.assets.create' => [$deny, $deny, $open, $open, $deny],
        'notifications.index' => [$open, $open, $open, $open, $open],
        'account.my-data' => [$open, $open, $open, $open, $open],
    ];

    foreach ($matrix as $routeName => $answers) {
        foreach (['student', 'scientist', 'auditor', 'admin', 'nocard'] as $i => $kind) {
            yield "{$routeName} as {$kind}" => [$routeName, $kind, $answers[$i]];
        }
    }
});

test('every important page answers each role exactly as before the menu moved', function (string $routeName, string $kind, int $expected) {
    $user = navUser($kind);

    $this->actingAs($user)->get(route($routeName))->assertStatus($expected);
})->with('direct page access');

test('every page behind login still sends a guest to the login page', function (string $routeName) {
    $this->get(route($routeName))->assertRedirect(route('login'));
})->with([
    'items.index', 'locations.index', 'stock-takes.index', 'disposals.index', 'adjustments.index',
    'reports.index', 'labs.members', 'admin.users.index', 'admin.labs.index', 'requisitions.index',
    'requisitions.manage', 'warehouse.overview', 'shop.index', 'stock-in.index', 'ims.lots.index',
    'loans.available', 'loans.cart', 'loans.assets.index', 'loans.assets.create', 'notifications.index', 'account.my-data',
]);

test('a shop URL with a type is gated like the plain shop: it adds no way in for someone who cannot request', function () {
    $nocard = navUser('nocard');

    $this->actingAs($nocard)->get(route('shop.index', ['type' => 'chemical']))->assertForbidden();
    $this->actingAs($nocard)->get(route('shop.index', ['type' => 'consumable']))->assertForbidden();
    $this->actingAs($nocard)->get(route('requisitions.index', ['type' => 'chemical']))->assertForbidden();
});

test('the old requisition pages other roles reach from the new tabs stay protected one by one (a STUDENT cannot open the manage queue or a stranger\'s requisition)', function () {
    $student = navUser('student');
    $stranger = navUser('student');
    $theirs = requisitionWithLines($stranger, ['CHEMICAL'], ['status' => 'SUBMITTED']);

    $this->actingAs($student)->get(route('requisitions.manage', ['tab' => 'waiting']))->assertForbidden();
    $this->actingAs($student)->get(route('requisitions.show', $theirs))->assertForbidden();
});

test('a warehouse manager of another branch still cannot open this branch\'s requisition, whatever tab leads there', function () {
    $labA = makeLab();
    $labB = makeLab();
    $req = requisitionWithLines(studentUser(['lab_id' => $labA->id]), ['CHEMICAL'], ['lab_id' => $labA->id, 'status' => 'ADVISOR_APPROVED']);

    $this->actingAs(auditorUser(['lab_id' => $labA->id]))->get(route('requisitions.show', $req))->assertOk();
    $this->actingAs(auditorUser(['lab_id' => $labB->id]))->get(route('requisitions.show', $req))->assertForbidden();
});

test('ADMIN still cannot write to the ledger from the new warehouse tabs: the adjustment form stays 403', function () {
    $admin = navUser('admin');

    $this->actingAs($admin)->get(route('adjustments.index'))->assertOk()->assertDontSee(route('adjustments.create'), false);
    $this->actingAs($admin)->get(route('adjustments.create'))->assertForbidden();
});
