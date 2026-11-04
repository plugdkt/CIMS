<?php

use App\Domain\Loan\Exceptions\LoanDomainException;
use App\Domain\Loan\Services\LoanLedgerHasher;
use App\Models\AuditLog;
use App\Models\LoanAsset;
use App\Models\LoanMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * ITM-2610-051 step 1: LoanLedgerService is the ONLY writer of loan_assets quantities and loan_movements.
 * Whole numbers only; only COUNT-unit items (BR-L01); one POOL per branch+item; adjust/retire need a note
 * and a DIFFERENT approver of that branch who holds loan.asset_approve (a scientist or manager; ADMIN never
 * counts as the approver, approvals.md K 2026-11-04); the module never touches
 * stock_ledger.
 */

const LOAN_NOTE = 'เหตุผลที่ยาวพอสำหรับทดสอบ';

// --- register -------------------------------------------------------------------------------------------

test('REGISTER a UNIT asset: one piece, an auto asset tag, a REGISTER movement, ACTIVE, nothing on loan', function () {
    [$lab, $manager] = loanManagers();

    $asset = loanService()->register($manager, ['lab_id' => $lab->id, 'item_id' => makeCountItem()->id, 'tracking_mode' => 'UNIT', 'qty' => 1]);

    expect($asset->tracking_mode)->toBe('UNIT')->and($asset->status)->toBe('ACTIVE');
    expect($asset->qty_total)->toBe('1.000000')->and($asset->qty_on_loan)->toBe('0.000000')->and($asset->qty_unavailable)->toBe('0.000000');
    expect($asset->asset_tag)->toStartWith('LA-');

    $movements = LoanMovement::where('loan_asset_id', $asset->id)->get();
    expect($movements)->toHaveCount(1);
    expect($movements[0]->movement_type)->toBe('REGISTER')->and($movements[0]->qty)->toBe('1.000000');
    expect($movements[0]->created_by)->toBe($manager->id)->and($movements[0]->approved_by)->toBeNull();
    expect($movements[0]->prev_row_hash)->toBeNull();
    expect(AuditLog::where('action', 'LOAN_ASSET_REGISTER')->where('entity_id', $asset->id)->count())->toBe(1);
});

test('REGISTER a POOL asset with a count; a UNIT asset must be exactly 1', function () {
    [$lab, $manager] = loanManagers();

    $pool = loanService()->register($manager, ['lab_id' => $lab->id, 'item_id' => makeCountItem()->id, 'tracking_mode' => 'POOL', 'qty' => 12]);
    expect($pool->qty_total)->toBe('12.000000')->and($pool->asset_tag)->toBeNull();

    foreach ([2, 12] as $bad) {
        expect(fn () => loanService()->register($manager, ['lab_id' => $lab->id, 'item_id' => makeCountItem()->id, 'tracking_mode' => 'UNIT', 'qty' => $bad]))
            ->toThrow(LoanDomainException::class, __('loans.error.unit_qty_one'));
    }
});

test('quantities are whole numbers only: 3.5, 0, -1, abc, empty, exponent and absurdly long values are refused', function (string $bad) {
    [$lab, $manager] = loanManagers();

    expect(fn () => loanService()->register($manager, ['lab_id' => $lab->id, 'item_id' => makeCountItem()->id, 'tracking_mode' => 'POOL', 'qty' => $bad]))
        ->toThrow(LoanDomainException::class, __('loans.error.qty_whole'));

    expect(LoanAsset::count())->toBe(0);
    expect(LoanMovement::count())->toBe(0);
})->with(['3.5', '0', '-1', 'abc', '', '1e3', '1234567890', ' ', '2,5']);

test('BR-L01: items whose base unit is mass, volume, or missing cannot be registered', function () {
    [$lab, $manager] = loanManagers();

    $mass = makeCountItem([], 'g');
    $volume = makeCountItem([], 'mL');
    $noUnit = makeItem(['base_unit_id' => null]);

    foreach ([$mass, $volume, $noUnit] as $item) {
        expect(fn () => loanService()->register($manager, ['lab_id' => $lab->id, 'item_id' => $item->id, 'tracking_mode' => 'POOL', 'qty' => 3]))
            ->toThrow(LoanDomainException::class, __('loans.error.item_not_countable'));
    }
    expect(LoanAsset::count())->toBe(0);
});

test('an inactive or unknown item cannot be registered', function () {
    [$lab, $manager] = loanManagers();
    $inactive = makeCountItem(['is_active' => false]);

    foreach ([$inactive->id, 999999] as $id) {
        expect(fn () => loanService()->register($manager, ['lab_id' => $lab->id, 'item_id' => $id, 'tracking_mode' => 'POOL', 'qty' => 1]))
            ->toThrow(LoanDomainException::class, __('loans.error.item_not_found'));
    }
});

test('only one POOL per branch and item: the service refuses a second, and the database unique key backs it up', function () {
    [$lab, $manager] = loanManagers();
    $item = makeCountItem();
    registerLoanAsset($manager, $lab, 'POOL', 5, $item);

    expect(fn () => registerLoanAsset($manager, $lab, 'POOL', 3, $item))->toThrow(LoanDomainException::class, __('loans.error.pool_exists'));

    // The same item in ANOTHER branch, or as UNIT pieces, is fine.
    $otherManager = auditorUser(['lab_id' => makeLab()->id]);
    expect(registerLoanAsset($otherManager, null, 'POOL', 3, $item)->exists)->toBeTrue();
    expect(registerLoanAsset($manager, $lab, 'UNIT', 1, $item)->exists)->toBeTrue();

    // Bypass the service: the generated pool_key unique index refuses the duplicate row on its own.
    expect(fn () => DB::table('loan_assets')->insert([
        'ulid' => (string) \Illuminate\Support\Str::ulid(), 'lab_id' => $lab->id, 'item_id' => $item->id, 'tracking_mode' => 'POOL',
        'qty_total' => 1, 'qty_on_loan' => 0, 'qty_unavailable' => 0, 'status' => 'ACTIVE',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('a duplicate asset tag is refused', function () {
    [$lab, $manager] = loanManagers();

    loanService()->register($manager, ['lab_id' => $lab->id, 'item_id' => makeCountItem()->id, 'tracking_mode' => 'UNIT', 'qty' => 1, 'asset_tag' => 'TAG-0001']);

    expect(fn () => loanService()->register($manager, ['lab_id' => $lab->id, 'item_id' => makeCountItem()->id, 'tracking_mode' => 'UNIT', 'qty' => 1, 'asset_tag' => 'TAG-0001']))
        ->toThrow(LoanDomainException::class, __('loans.error.tag_exists'));
});

test('who may register: a manager only into their own branch, ADMIN into any, nobody without loan.asset_manage', function () {
    [$lab, $manager] = loanManagers();
    $otherLab = makeLab();
    $item = makeCountItem();

    expect(fn () => loanService()->register($manager, ['lab_id' => $otherLab->id, 'item_id' => $item->id, 'tracking_mode' => 'POOL', 'qty' => 1]))
        ->toThrow(LoanDomainException::class, __('loans.error.not_allowed'));

    $admin = adminUser();
    expect(loanService()->register($admin, ['lab_id' => $otherLab->id, 'item_id' => $item->id, 'tracking_mode' => 'POOL', 'qty' => 1])->lab_id)->toBe($otherLab->id);

    $without = [
        scientistUser(['lab_id' => $lab->id]), staffUser(['lab_id' => $lab->id]), studentUser(['lab_id' => $lab->id]),
        auditorUser(['lab_id' => null]), auditorUser(['lab_id' => $lab->id, 'is_active' => false]),
    ];
    foreach ($without as $user) {
        expect(fn () => loanService()->register($user, ['lab_id' => $lab->id, 'item_id' => makeCountItem()->id, 'tracking_mode' => 'POOL', 'qty' => 1]))
            ->toThrow(LoanDomainException::class, __('loans.error.not_allowed'));
    }
});

test('a location of another branch is refused', function () {
    [$lab, $manager] = loanManagers();
    $foreign = makeLocationForLab(makeLab());

    expect(fn () => loanService()->register($manager, ['lab_id' => $lab->id, 'item_id' => makeCountItem()->id, 'tracking_mode' => 'POOL', 'qty' => 1, 'location_id' => $foreign->id]))
        ->toThrow(LoanDomainException::class, __('loans.error.location_not_in_lab'));
});

test('the module never writes stock_ledger', function () {
    [$lab, $manager, $approver] = loanManagers();
    $before = \App\Models\StockLedger::count();

    $asset = registerLoanAsset($manager, $lab, 'POOL', 10);
    loanService()->adjust($manager, $asset, 'IN', 2, 'นับใหม่พบเพิ่ม 2 ชิ้น', null, $approver->id);
    loanService()->retire($manager, $asset->refresh(), 'ปลดระวางทั้งกอง ไม่ใช้งานแล้ว', $approver->id);

    expect(\App\Models\StockLedger::count())->toBe($before);
});

// --- adjust ----------------------------------------------------------------------------------------------

test('ADJUST_IN / ADJUST_OUT move the total, record the approver, and need a note of at least 10 characters', function () {
    [$lab, $manager, $approver] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 10);

    loanService()->adjust($manager, $asset, 'IN', 3, 'นับสต็อกใหม่พบเพิ่มสามชิ้น', null, $approver->id);
    expect($asset->fresh()->qty_total)->toBe('13.000000');

    loanService()->adjust($manager, $asset->fresh(), 'OUT', 4, 'ชำรุดนอกระบบ ตัดออกสี่ชิ้น', null, $approver->id);
    expect($asset->fresh()->qty_total)->toBe('9.000000');

    $rows = LoanMovement::where('loan_asset_id', $asset->id)->orderBy('id')->get();
    expect($rows->pluck('movement_type')->all())->toBe(['REGISTER', 'ADJUST_IN', 'ADJUST_OUT']);
    expect($rows[1]->approved_by)->toBe($approver->id)->and($rows[1]->created_by)->toBe($manager->id);
    expect($rows[2]->qty_total_after)->toBe('9.000000');

    foreach (['', 'สั้นไป', '         x'] as $shortNote) {
        expect(fn () => loanService()->adjust($manager, $asset->fresh(), 'IN', 1, $shortNote, null, $approver->id))
            ->toThrow(LoanDomainException::class, __('loans.error.note_too_short'));
    }
});

test('adjust and retire need a DIFFERENT, active approver of that branch who holds loan.asset_approve (approvals.md K: scientists and managers; ADMIN can never stand in)', function () {
    [$lab, $manager, $colleague] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 10);

    $bad = [
        'the actor themself' => $manager->id,
        'a manager of another branch' => auditorUser(['lab_id' => makeLab()->id])->id,
        'a scientist of another branch' => scientistUser(['lab_id' => makeLab()->id])->id,
        'an inactive manager' => auditorUser(['lab_id' => $lab->id, 'is_active' => false])->id,
        'an inactive scientist' => scientistUser(['lab_id' => $lab->id, 'is_active' => false])->id,
        'a user without loan.asset_approve' => staffUser(['lab_id' => $lab->id])->id,
        'a manager with no branch' => auditorUser(['lab_id' => null])->id,
        'a scientist with no branch' => scientistUser(['lab_id' => null])->id,
        'an ADMIN of the same branch' => adminUser(['lab_id' => $lab->id])->id,
        'an ADMIN with no branch' => adminUser()->id,
        'an unknown user' => 999999,
    ];
    foreach ($bad as $label => $approverId) {
        expect(fn () => loanService()->adjust($manager, $asset->fresh(), 'IN', 1, LOAN_NOTE, null, $approverId))
            ->toThrow(LoanDomainException::class, __('loans.error.approver_invalid'));
        expect(fn () => loanService()->retire($manager, $asset->fresh(), LOAN_NOTE, $approverId))
            ->toThrow(LoanDomainException::class, __('loans.error.approver_invalid'));
    }
    expect($asset->fresh()->qty_total)->toBe('10.000000')->and($asset->fresh()->status)->toBe('ACTIVE');
    // Nothing was written by any refused attempt: still only the REGISTER movement.
    expect(LoanMovement::where('loan_asset_id', $asset->id)->count())->toBe(1);

    loanService()->adjust($manager, $asset->fresh(), 'IN', 1, LOAN_NOTE, null, $colleague->id);
    $scientist = scientistUser(['lab_id' => $lab->id]);
    loanService()->adjust($manager, $asset->fresh(), 'IN', 1, LOAN_NOTE, null, $scientist->id);
    expect($asset->fresh()->qty_total)->toBe('12.000000');
    expect(LoanMovement::where('loan_asset_id', $asset->id)->where('approved_by', $scientist->id)->count())->toBe(1);

    // A scientist can approve but cannot be the one who does an ADJUST/RETIRE (that stays loan.asset_manage).
    expect(fn () => loanService()->adjust($scientist, $asset->fresh(), 'IN', 1, LOAN_NOTE, null, $manager->id))
        ->toThrow(LoanDomainException::class, __('loans.error.not_allowed'));
    expect($asset->fresh()->qty_total)->toBe('12.000000');
});

test('approvals.md K: ADMIN (who may still do an ADJUST/RETIRE as the actor) needs a branch approver and is never accepted as one', function () {
    [$lab, $manager] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 10);
    $admin = adminUser();

    expect(fn () => loanService()->adjust($admin, $asset->fresh(), 'IN', 1, LOAN_NOTE, null, $admin->id))
        ->toThrow(LoanDomainException::class, __('loans.error.approver_invalid'));
    loanService()->adjust($admin, $asset->fresh(), 'IN', 1, LOAN_NOTE, null, $manager->id);
    expect($asset->fresh()->qty_total)->toBe('11.000000');

    // And the other direction: a manager cannot name ADMIN, even an ADMIN who holds a branch.
    expect(fn () => loanService()->retire($manager, $asset->fresh(), LOAN_NOTE, adminUser(['lab_id' => $lab->id])->id))
        ->toThrow(LoanDomainException::class, __('loans.error.approver_invalid'));
    expect($asset->fresh()->status)->toBe('ACTIVE');
});

test('adjusting is limited to the actor\'s own branch (ADMIN: any) and to POOL assets', function () {
    [$lab, $manager, $approver] = loanManagers();
    [$otherLab, $otherManager] = loanManagers();
    $pool = registerLoanAsset($manager, $lab, 'POOL', 5);
    $unit = registerLoanAsset($manager, $lab, 'UNIT');

    expect(fn () => loanService()->adjust($otherManager, $pool, 'IN', 1, LOAN_NOTE, null, $approver->id))
        ->toThrow(LoanDomainException::class, __('loans.error.not_allowed'));
    expect(fn () => loanService()->retire($otherManager, $pool, LOAN_NOTE, $approver->id))
        ->toThrow(LoanDomainException::class, __('loans.error.not_allowed'));
    expect(fn () => loanService()->adjust($manager, $unit, 'IN', 1, LOAN_NOTE, null, $approver->id))
        ->toThrow(LoanDomainException::class, __('loans.error.adjust_pool_only'));

    // ADMIN acting is fine in any branch as long as a real branch approver (manager of that branch) is named.
    loanService()->adjust(adminUser(), $pool, 'IN', 1, LOAN_NOTE, null, $manager->id);
    expect($pool->fresh()->qty_total)->toBe('6.000000');
});

test('ADJUST_OUT cannot take the total below what is out and unusable, nor down to zero', function () {
    [$lab, $manager, $approver] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 10);
    // Stand-in for later steps (hand-out / damage): set the counters directly in the test database.
    DB::table('loan_assets')->where('id', $asset->id)->update(['qty_on_loan' => 4, 'qty_unavailable' => 2]);

    // 10 - 5 = 5 < 4 + 2
    expect(fn () => loanService()->adjust($manager, $asset->fresh(), 'OUT', 5, LOAN_NOTE, null, $approver->id))
        ->toThrow(LoanDomainException::class, __('loans.error.adjust_out_too_much'));
    // Exactly at the floor is allowed: 10 - 4 = 6.
    loanService()->adjust($manager, $asset->fresh(), 'OUT', 4, LOAN_NOTE, null, $approver->id);
    expect($asset->fresh()->qty_total)->toBe('6.000000');

    // Down to zero is refused (retire instead).
    $small = registerLoanAsset($manager, $lab, 'POOL', 3);
    expect(fn () => loanService()->adjust($manager, $small, 'OUT', 3, LOAN_NOTE, null, $approver->id))
        ->toThrow(LoanDomainException::class, __('loans.error.adjust_out_too_much'));
    expect($small->fresh()->qty_total)->toBe('3.000000');
});

test('lowering an asset that was ever handed out needs an outside reference of at least 5 characters', function () {
    [$lab, $manager, $approver] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 10);

    // No HANDOUT movements exist yet in step 1, so make the history look like one (INSERT is allowed).
    $last = LoanMovement::where('loan_asset_id', $asset->id)->latest('id')->firstOrFail();
    $row = new LoanMovement([
        'loan_asset_id' => $asset->id, 'lab_id' => $lab->id, 'movement_type' => 'HANDOUT', 'qty' => '1.000000',
        'qty_total_after' => '10.000000', 'qty_on_loan_after' => '0.000000', 'qty_unavailable_after' => '0.000000',
        'created_by' => $manager->id, 'created_at' => now(), 'prev_row_hash' => $last->row_hash,
    ]);
    $row->row_hash = app(LoanLedgerHasher::class)->compute($row);
    $row->save();

    expect(fn () => loanService()->adjust($manager, $asset->fresh(), 'OUT', 1, LOAN_NOTE, null, $approver->id))
        ->toThrow(LoanDomainException::class, __('loans.error.external_ref_required'));
    expect(fn () => loanService()->adjust($manager, $asset->fresh(), 'OUT', 1, LOAN_NOTE, 'abc', $approver->id))
        ->toThrow(LoanDomainException::class, __('loans.error.external_ref_required'));

    loanService()->adjust($manager, $asset->fresh(), 'OUT', 1, LOAN_NOTE, 'หนังสือ ศธ 0123/45', $approver->id);
    expect($asset->fresh()->qty_total)->toBe('9.000000');
    expect(LoanMovement::where('movement_type', 'ADJUST_OUT')->firstOrFail()->external_ref)->toBe('หนังสือ ศธ 0123/45');
});

// --- retire ---------------------------------------------------------------------------------------------

test('RETIRE needs a note and a different approver, marks the asset RETIRED and keeps its totals', function () {
    [$lab, $manager, $approver] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 6);

    expect(fn () => loanService()->retire($manager, $asset, 'สั้น', $approver->id))->toThrow(LoanDomainException::class, __('loans.error.note_too_short'));

    loanService()->retire($manager, $asset->fresh(), 'ปลดระวางเพราะเสื่อมสภาพทั้งหมด', $approver->id);

    $fresh = $asset->fresh();
    expect($fresh->status)->toBe('RETIRED')->and($fresh->qty_total)->toBe('6.000000');
    $row = LoanMovement::where('loan_asset_id', $asset->id)->where('movement_type', 'RETIRE')->firstOrFail();
    expect($row->qty)->toBe('6.000000')->and($row->approved_by)->toBe($approver->id)->and($row->qty_total_after)->toBe('6.000000');
});

test('RETIRE is refused while anything is on loan, and the asset stays ACTIVE', function () {
    [$lab, $manager, $approver] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 6);
    DB::table('loan_assets')->where('id', $asset->id)->update(['qty_on_loan' => 2]);   // set directly: hand-out arrives in a later step

    expect(fn () => loanService()->retire($manager, $asset->fresh(), 'ปลดระวางเพราะเสื่อมสภาพทั้งหมด', $approver->id))
        ->toThrow(LoanDomainException::class, __('loans.error.retire_while_on_loan'));

    expect($asset->fresh()->status)->toBe('ACTIVE');
    expect(LoanMovement::where('movement_type', 'RETIRE')->count())->toBe(0);
});

test('after RETIRE nothing can be changed: adjust, retire again, and the edit form are all refused', function () {
    [$lab, $manager, $approver] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 6);
    loanService()->retire($manager, $asset, LOAN_NOTE, $approver->id);
    $retired = $asset->fresh();

    expect(fn () => loanService()->adjust($manager, $retired, 'IN', 1, LOAN_NOTE, null, $approver->id))->toThrow(LoanDomainException::class, __('loans.error.asset_retired'));
    expect(fn () => loanService()->retire($manager, $retired, LOAN_NOTE, $approver->id))->toThrow(LoanDomainException::class, __('loans.error.asset_retired'));

    $this->actingAs($manager)->put(route('loans.assets.update', $retired), ['max_loan_days' => 5])->assertForbidden();
    $this->actingAs($manager)->post(route('loans.assets.adjust', $retired), ['direction' => 'IN', 'qty' => 1, 'note' => LOAN_NOTE, 'approved_by' => $approver->id])->assertForbidden();
    expect(LoanMovement::where('loan_asset_id', $asset->id)->count())->toBe(2);   // REGISTER + RETIRE only
});

// --- the chain ---------------------------------------------------------------------------------------------

test('movements form one unbroken hash chain per asset, and the asset totals equal the latest movement', function () {
    [$lab, $manager, $approver] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 10);
    $other = registerLoanAsset($manager, $lab, 'POOL', 4);

    loanService()->adjust($manager, $asset->fresh(), 'IN', 2, LOAN_NOTE, null, $approver->id);
    loanService()->adjust($manager, $other->fresh(), 'IN', 1, LOAN_NOTE, null, $approver->id);
    loanService()->adjust($manager, $asset->fresh(), 'OUT', 3, LOAN_NOTE, null, $approver->id);
    loanService()->retire($manager, $asset->fresh(), LOAN_NOTE, $approver->id);

    $rows = LoanMovement::where('loan_asset_id', $asset->id)->orderBy('id')->get();
    expect($rows)->toHaveCount(4);
    expect($rows[0]->prev_row_hash)->toBeNull();
    foreach ($rows as $i => $row) {
        if ($i > 0) {
            expect($row->prev_row_hash)->toBe($rows[$i - 1]->row_hash);
        }
        expect($row->row_hash)->toBe(app(LoanLedgerHasher::class)->compute($row));
    }

    // Each asset has its own chain: the other asset's first row does not link to this one.
    expect(LoanMovement::where('loan_asset_id', $other->id)->orderBy('id')->first()->prev_row_hash)->toBeNull();

    expect(app(LoanLedgerHasher::class)->verifyChain($asset->id))->toBe(['ok' => true, 'broken_at' => null]);
    expect(app(LoanLedgerHasher::class)->verifyChain($other->id))->toBe(['ok' => true, 'broken_at' => null]);

    $last = $rows->last();
    $final = $asset->fresh();
    expect($final->qty_total)->toBe($last->qty_total_after)->and($final->qty_on_loan)->toBe($last->qty_on_loan_after)->and($final->qty_unavailable)->toBe($last->qty_unavailable_after);
});

test('a movement written with a wrong hash is reported by verifyChain at that row', function () {
    [$lab, $manager] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 10);
    $first = LoanMovement::where('loan_asset_id', $asset->id)->firstOrFail();

    $forged = new LoanMovement([
        'loan_asset_id' => $asset->id, 'lab_id' => $lab->id, 'movement_type' => 'HANDOUT', 'qty' => '1.000000',
        'qty_total_after' => '10.000000', 'qty_on_loan_after' => '1.000000', 'qty_unavailable_after' => '0.000000',
        'created_by' => $manager->id, 'created_at' => now(), 'prev_row_hash' => $first->row_hash, 'row_hash' => str_repeat('0', 64),
    ]);
    $forged->save();

    expect(app(LoanLedgerHasher::class)->verifyChain($asset->id))->toBe(['ok' => false, 'broken_at' => $forged->id]);
});

test('quantities and status of a loan asset are not mass-assignable', function () {
    [$lab] = loanManagers();

    $asset = new LoanAsset(['lab_id' => $lab->id, 'item_id' => makeCountItem()->id, 'tracking_mode' => 'POOL', 'qty_total' => 99, 'qty_on_loan' => 50, 'qty_unavailable' => 5, 'status' => 'RETIRED']);

    expect($asset->qty_total)->toBeNull()->and($asset->qty_on_loan)->toBeNull()->and($asset->qty_unavailable)->toBeNull();
    expect($asset->status)->toBeNull();
    expect($asset->lab_id)->toBe($lab->id);
});
