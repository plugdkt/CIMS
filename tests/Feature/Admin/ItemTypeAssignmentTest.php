<?php

use App\Livewire\Admin\UserRoleManager;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * ITM-2610-052 phase 3: who is responsible for which kind of stock is set by ADMIN only, on the
 * users page, and every change is audited. Also the one-off command that gives the managers that
 * already exist both kinds at deploy (the migration itself creates an empty table).
 */

if (! function_exists('storedKinds')) {
    /** @return list<string> */
    function storedKinds(User $user): array
    {
        return DB::table('user_item_types')->where('user_id', $user->id)->orderBy('item_type')->pluck('item_type')->all();
    }
}

// --- migration -------------------------------------------------------------------------------------

test('the migration creates an empty user_item_types table: nothing is backfilled by it', function () {
    expect(Schema::hasTable('user_item_types'))->toBeTrue();
    expect(Schema::hasColumns('user_item_types', ['user_id', 'item_type']))->toBeTrue();
    expect(DB::table('user_item_types')->count())->toBe(0);
});

test('a manager created without rows holds no kind at all (deny by default)', function () {
    $manager = auditorUser(['itemTypes' => []]);

    expect($manager->managedItemTypes())->toBe([]);
    expect($manager->managesItemType('chemical'))->toBeFalse();
    expect($manager->managesItemType('consumable'))->toBeFalse();
});

// --- ADMIN assigns kinds in the UI --------------------------------------------------------------------

test('ADMIN can switch a kind on and off for a manager, each change audited old to new', function () {
    $admin = userWithRole('ADMIN');
    $manager = auditorUser(['itemTypes' => []]);

    Livewire::actingAs($admin)->test(UserRoleManager::class)->call('toggleItemType', $manager->id, 'chemical');
    expect(storedKinds($manager))->toBe(['chemical']);

    Livewire::actingAs($admin)->test(UserRoleManager::class)->call('toggleItemType', $manager->id, 'consumable');
    expect(storedKinds($manager))->toBe(['chemical', 'consumable']);

    Livewire::actingAs($admin)->test(UserRoleManager::class)->call('toggleItemType', $manager->id, 'chemical');
    expect(storedKinds($manager))->toBe(['consumable']);

    $rows = AuditLog::where('action', 'ITEM_TYPE_CHANGE')->where('entity_id', $manager->id)->orderBy('id')->get();
    expect($rows)->toHaveCount(3);
    expect($rows[0]->old_value)->toBe(['item_types' => []]);
    expect($rows[0]->new_value)->toBe(['item_types' => ['chemical']]);
    expect($rows[1]->old_value)->toBe(['item_types' => ['chemical']]);
    expect($rows[1]->new_value)->toBe(['item_types' => ['chemical', 'consumable']]);
    expect($rows[2]->old_value)->toBe(['item_types' => ['chemical', 'consumable']]);
    expect($rows[2]->new_value)->toBe(['item_types' => ['consumable']]);
    expect($rows[0]->user_id)->toBe($admin->id);
    expect($rows[0]->entity_type)->toBe(User::class);
});

test('a manager (or anyone who is not ADMIN) cannot change kinds, not even their own', function () {
    $manager = auditorUser(['itemTypes' => ['chemical']]);
    $other = labManagerUser(['itemTypes' => []]);

    // The page itself refuses non-ADMIN accounts.
    $this->actingAs($manager)->get(route('admin.users.index'))->assertForbidden();

    // And the action refuses them even if reached directly.
    $this->actingAs($manager);
    foreach ([$manager, $other] as $target) {
        expect(fn () => (new UserRoleManager())->toggleItemType($target->id, 'consumable'))->toThrow(AuthorizationException::class);
    }

    expect(storedKinds($manager))->toBe(['chemical']);
    expect(storedKinds($other))->toBe([]);
    expect(AuditLog::where('action', 'ITEM_TYPE_CHANGE')->count())->toBe(0);
});

test('only accounts holding LAB_MANAGER or AUDITOR can be given kinds', function () {
    $admin = userWithRole('ADMIN');

    foreach (['STAFF', 'SCIENTIST', 'STUDENT', 'ADVISOR', 'ADMIN'] as $roleCode) {
        $target = userWithRole($roleCode);

        Livewire::actingAs($admin)->test(UserRoleManager::class)->call('toggleItemType', $target->id, 'chemical');

        expect(storedKinds($target))->toBe([], $roleCode);
    }
    expect(AuditLog::where('action', 'ITEM_TYPE_CHANGE')->count())->toBe(0);

    // A manager who also holds another role is fine.
    $both = auditorUser(['itemTypes' => []]);
    $both->roles()->attach(Role::where('code', 'SCIENTIST')->firstOrFail());
    Livewire::actingAs($admin)->test(UserRoleManager::class)->call('toggleItemType', $both->id, 'consumable');
    expect(storedKinds($both))->toBe(['consumable']);
});

test('an unknown kind is ignored: nothing stored, nothing audited', function (string $bad) {
    $admin = userWithRole('ADMIN');
    $manager = labManagerUser(['itemTypes' => []]);

    Livewire::actingAs($admin)->test(UserRoleManager::class)->call('toggleItemType', $manager->id, $bad);

    expect(storedKinds($manager))->toBe([]);
    expect(DB::table('user_item_types')->count())->toBe(0);
    expect(AuditLog::where('action', 'ITEM_TYPE_CHANGE')->count())->toBe(0);
})->with(['CHEMICAL', 'both', '', "chemical' OR 1=1", 'CHEMICAL,CONSUMABLE']);

test('the users page shows a pressed/unpressed toggle per kind for managers only, and the "not set" warning when a manager has none', function () {
    $admin = userWithRole('ADMIN');
    $lab = makeLab();
    $manager = auditorUser(['lab_id' => $lab->id, 'itemTypes' => ['chemical']]);
    $empty = labManagerUser(['lab_id' => $lab->id, 'itemTypes' => []]);
    $staff = userWithRole('STAFF');
    $staff->update(['lab_id' => $lab->id]);

    $component = Livewire::actingAs($admin)->test(UserRoleManager::class)->set('tab', (string) $lab->id);
    $xp = htmlXPath($component->html());
    $button = fn (User $user, string $kind) => $xp->query('//button[@*[name()="wire:click" and .="toggleItemType('.$user->id.', \''.$kind.'\')"]]');

    expect($button($manager, 'chemical')->length)->toBe(1);
    expect($button($manager, 'chemical')->item(0)->getAttribute('aria-pressed'))->toBe('true');
    expect($button($manager, 'consumable')->item(0)->getAttribute('aria-pressed'))->toBe('false');
    expect($button($empty, 'chemical')->item(0)->getAttribute('aria-pressed'))->toBe('false');
    expect($button($empty, 'consumable')->item(0)->getAttribute('aria-pressed'))->toBe('false');
    expect($button($staff, 'chemical')->length)->toBe(0);   // not a manager: no toggles

    $component->assertSee(__('admin.item_types_none'));
});

// --- the deploy-time backfill command ------------------------------------------------------------------

test('managers:backfill-item-types gives both kinds to managers that have none, and to nobody else', function () {
    $labManager = labManagerUser(['itemTypes' => []]);
    $auditor = auditorUser(['itemTypes' => []]);
    $multi = auditorUser(['itemTypes' => []]);
    $multi->roles()->attach(Role::where('code', 'SCIENTIST')->firstOrFail());
    $scientist = scientistUser();
    $admin = adminUser();
    $staff = staffUser();
    $student = studentUser();

    $this->artisan('managers:backfill-item-types')->assertSuccessful();

    foreach ([$labManager, $auditor, $multi] as $manager) {
        expect(storedKinds($manager))->toBe(['chemical', 'consumable']);
    }
    foreach ([$scientist, $admin, $staff, $student] as $other) {
        expect(storedKinds($other))->toBe([]);
    }
});

test('managers:backfill-item-types leaves managers that already have a kind alone, including ones narrowed on purpose', function () {
    $chemOnly = labManagerUser(['itemTypes' => ['chemical']]);
    $supplyOnly = auditorUser(['itemTypes' => ['consumable']]);
    $both = auditorUser();
    $fresh = labManagerUser(['itemTypes' => []]);

    $this->artisan('managers:backfill-item-types')->assertSuccessful();

    expect(storedKinds($chemOnly))->toBe(['chemical']);       // not given the removed kind back
    expect(storedKinds($supplyOnly))->toBe(['consumable']);
    expect(storedKinds($both))->toBe(['chemical', 'consumable']);
    expect(storedKinds($fresh))->toBe(['chemical', 'consumable']);
});

test('managers:backfill-item-types can be run again without adding or restoring anything', function () {
    $manager = auditorUser(['itemTypes' => []]);

    $this->artisan('managers:backfill-item-types')->assertSuccessful();
    $count = DB::table('user_item_types')->count();
    expect($count)->toBe(2);

    // An ADMIN then narrows the manager to one kind; a later run must not undo that.
    DB::table('user_item_types')->where('user_id', $manager->id)->where('item_type', 'consumable')->delete();

    $this->artisan('managers:backfill-item-types')->assertSuccessful();
    $this->artisan('managers:backfill-item-types')->assertSuccessful();

    expect(storedKinds($manager))->toBe(['chemical']);
    expect(DB::table('user_item_types')->count())->toBe(1);
});

test('managers:backfill-item-types --dry-run writes no rows and no audit entry, but reports how many managers it would touch', function () {
    labManagerUser(['itemTypes' => []]);
    auditorUser(['itemTypes' => []]);
    auditorUser();   // already has both kinds: not counted
    scientistUser(); // not a manager: not counted
    $before = DB::table('user_item_types')->count();

    $this->artisan('managers:backfill-item-types', ['--dry-run' => true])
        ->expectsOutputToContain('จะเพิ่มชนิดให้ผู้ดูแลคลัง 2 คน')
        ->assertSuccessful();

    expect(DB::table('user_item_types')->count())->toBe($before);
    expect(AuditLog::where('action', 'ITEM_TYPE_BACKFILL')->count())->toBe(0);
});

test('managers:backfill-item-types writes one audit entry when it adds rows, and none when there is nothing to add', function () {
    labManagerUser(['itemTypes' => []]);

    $this->artisan('managers:backfill-item-types')->assertSuccessful();
    expect(AuditLog::where('action', 'ITEM_TYPE_BACKFILL')->count())->toBe(1);

    $this->artisan('managers:backfill-item-types')->assertSuccessful();
    expect(AuditLog::where('action', 'ITEM_TYPE_BACKFILL')->count())->toBe(1);
});

test('the backfill is not a routine job: a manager an ADMIN narrowed to nothing gets both kinds back on a re-run (documented in the admin manual)', function () {
    $manager = auditorUser(['itemTypes' => []]);
    $this->artisan('managers:backfill-item-types')->assertSuccessful();
    DB::table('user_item_types')->where('user_id', $manager->id)->delete();   // an ADMIN removes every kind

    $this->artisan('managers:backfill-item-types')->assertSuccessful();

    expect(storedKinds($manager))->toBe(['chemical', 'consumable']);
});

// --- Super Admin (ADMIN + LAB_MANAGER/AUDITOR) ----------------------------------------------------------

test('a Super Admin (ADMIN with a manager role) is not narrowed by kind or branch: approves any requisition, with no user_item_types rows', function () {
    $super = adminUser(['lab_id' => makeLab()->id]);
    $super->roles()->attach(Role::where('code', 'AUDITOR')->firstOrFail());   // deliberately no rows
    expect(storedKinds($super))->toBe([]);
    expect($super->fresh()->isBranchManager())->toBeFalse();

    foreach ([['CHEMICAL'], ['MATERIAL'], ['CHEMICAL', 'GLASSWARE']] as $categories) {
        $lab = makeLab();   // a branch that is not the Super Admin's own
        $requisition = requisitionWithLines(staffUser(['lab_id' => $lab->id]), $categories, ['lab_id' => $lab->id, 'status' => 'SUBMITTED']);

        $this->actingAs($super->fresh())->post(route('requisitions.scientist-decide', $requisition), ['decision' => 'APPROVE'])
            ->assertRedirect(route('requisitions.show', $requisition));

        expect($requisition->fresh()->status)->toBe('APPROVED', implode('+', $categories));
    }
});

test('a plain ADMIN (no manager role) still cannot approve any requisition of any kind', function () {
    $admin = adminUser();

    foreach ([['CHEMICAL'], ['MATERIAL'], ['CHEMICAL', 'GLASSWARE']] as $categories) {
        $requisition = requisitionWithLines(staffUser(), $categories, ['status' => 'SUBMITTED']);

        $this->actingAs($admin)->post(route('requisitions.scientist-decide', $requisition), ['decision' => 'APPROVE'])->assertForbidden();

        expect($requisition->fresh()->status)->toBe('SUBMITTED');
    }
});

test('the users page shows no kind toggles for an account that holds ADMIN, with the explanation instead; a plain manager still has them', function () {
    $admin = userWithRole('ADMIN');
    $lab = makeLab();
    $super = adminUser(['lab_id' => $lab->id]);
    $super->roles()->attach(Role::where('code', 'AUDITOR')->firstOrFail());
    $manager = auditorUser(['lab_id' => $lab->id, 'itemTypes' => ['chemical']]);

    $component = Livewire::actingAs($admin)->test(UserRoleManager::class)->set('tab', (string) $lab->id);
    $xp = htmlXPath($component->html());
    $button = fn (User $user, string $kind) => $xp->query('//button[@*[name()="wire:click" and .="toggleItemType('.$user->id.', \''.$kind.'\')"]]');

    expect($button($super, 'chemical')->length)->toBe(0);
    expect($button($super, 'consumable')->length)->toBe(0);
    expect($button($manager, 'chemical')->length)->toBe(1);
    $component->assertSee('บัญชีนี้เป็นผู้ดูแลระบบ จึงไม่ถูกจำกัดชนิด');
});

// --- self-approval inside the manager's own branch (CEO decision 2026-11-02) --------------------------------

test('a warehouse manager can approve a requisition they filed themself, inside their own branch (CEO decision, no ban)', function () {
    $lab = makeLab();
    $manager = auditorUser(['lab_id' => $lab->id, 'person_type' => 'STAFF']);
    $own = requisitionWithLines($manager, ['CHEMICAL'], ['lab_id' => $lab->id, 'status' => 'SUBMITTED']);

    $this->actingAs($manager)->post(route('requisitions.scientist-decide', $own), ['decision' => 'APPROVE'])
        ->assertRedirect(route('requisitions.show', $own));

    $fresh = $own->fresh();
    expect($fresh->status)->toBe('APPROVED');
    expect($fresh->scientist_id)->toBe($manager->id);
    expect($fresh->requester_id)->toBe($manager->id);
});

test('toggleItemType on an account that holds ADMIN does nothing: no row, no audit; the page shows the exemption text instead of toggles', function () {
    $admin = userWithRole('ADMIN');
    $lab = makeLab();
    $super = adminUser(['lab_id' => $lab->id]);
    $super->roles()->attach(Role::where('code', 'LAB_MANAGER')->firstOrFail());
    $super->roles()->attach(Role::where('code', 'AUDITOR')->firstOrFail());

    foreach (['chemical', 'consumable'] as $kind) {
        Livewire::actingAs($admin)->test(UserRoleManager::class)->call('toggleItemType', $super->id, $kind);
    }

    expect(storedKinds($super))->toBe([]);
    expect(AuditLog::where('action', 'ITEM_TYPE_CHANGE')->count())->toBe(0);

    $component = Livewire::actingAs($admin)->test(UserRoleManager::class)->set('tab', (string) $lab->id);
    $component->assertSee(__('admin.item_types_admin_exempt'));
    $xp = htmlXPath($component->html());
    expect($xp->query('//button[@*[name()="wire:click" and starts-with(., "toggleItemType('.$super->id.',")]]')->length)->toBe(0);
});

test('a plain manager can still be switched on and off, and switching on never creates a duplicate row', function () {
    $admin = userWithRole('ADMIN');
    $manager = labManagerUser(['itemTypes' => []]);

    Livewire::actingAs($admin)->test(UserRoleManager::class)->call('toggleItemType', $manager->id, 'chemical');
    expect(storedKinds($manager))->toBe(['chemical']);

    // The insert is insertOrIgnore: a second "on" that races the first must not fail or double up.
    \App\Models\UserItemType::insertOrIgnore([['user_id' => $manager->id, 'item_type' => 'chemical']]);
    expect(DB::table('user_item_types')->where('user_id', $manager->id)->count())->toBe(1);

    Livewire::actingAs($admin)->test(UserRoleManager::class)->call('toggleItemType', $manager->id, 'chemical');
    expect(storedKinds($manager))->toBe([]);
});
