<?php

use App\Domain\Inventory\Exceptions\ImsException;
use App\Domain\Inventory\Services\ImsBatchTransferService;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ImsLot;
use App\Models\ImsMovement;
use App\Models\StockLedger;
use App\Models\Unit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/*
 * ITM-2610-062: move many IMS lots into working stock against ONE paper requisition.
 * create (fill in) -> review (summary, writes nothing) -> store (one transaction, one-time token).
 * Quantities on the page are WHOLE PACKS; a lot with a pack size becomes one container per pack.
 */

beforeEach(function () {
    $this->lab = makeLab();
    $this->otherLab = makeLab();
    $this->location = makeLocationForLab($this->lab);
    $this->otherLocation = makeLocationForLab($this->otherLab);

    $this->chemA = makeItem(['name_th' => 'สารเคมี เอ']);
    $this->chemB = makeItem(['name_th' => 'สารเคมี บี']);
    $this->supply = consumableItem(['name_th' => 'วัสดุ เอส']);

    $this->lotA = makeImsLot($this->lab->id, $this->chemA, '4', '500');     // 2000 g
    $this->lotB = makeImsLot($this->lab->id, $this->chemB, '3', '250');     // 750 g
    $this->lotS = makeImsLot($this->lab->id, $this->supply, '4', '100');    // 400 g

    $this->manager = labManagerUser(['lab_id' => $this->lab->id]);          // keeps both kinds
    $this->chemKeeper = labManagerUser(['lab_id' => $this->lab->id, 'itemTypes' => ['chemical']]);

    $this->row = fn (ImsLot $lot, string|int $packs, $location = null) => [
        'lot' => $lot->ulid, 'qty' => (string) $packs, 'location' => ($location ?? $this->location)->ulid,
    ];

    // Step 2: the review page hands out the one-time token.
    $this->review = function ($user, array $rows, string $doc = 'WD-69-0042', ?string $remark = null) {
        $response = $this->actingAs($user)->post(route('ims.transfers.review'), ['ims_doc_no' => $doc, 'remark' => $remark, 'rows' => $rows])->assertOk();

        return $response->viewData('token');
    };

    $this->store = fn ($user, array $rows, string $token, string $doc = 'WD-69-0042', ?string $remark = null) => $this->actingAs($user)
        ->post(route('ims.transfers.store'), ['ims_doc_no' => $doc, 'remark' => $remark, 'rows' => $rows, 'token' => $token]);

    // review + store in one go
    $this->move = fn ($user, array $rows, string $doc = 'WD-69-0042', ?string $remark = null) => ($this->store)($user, $rows, ($this->review)($user, $rows, $doc, $remark), $doc, $remark);

    // Everything the moves could have written.
    $this->written = fn () => [
        'containers' => Container::count(),
        'ledger' => StockLedger::count(),
        'issues' => ImsMovement::where('type', 'ISSUE')->count(),
        'audit' => AuditLog::where('action', 'IMS_TRANSFER_BATCH')->count(),
        'a' => (string) $this->lotA->fresh()->qty_remaining_base,
        'b' => (string) $this->lotB->fresh()->qty_remaining_base,
        's' => (string) $this->lotS->fresh()->qty_remaining_base,
    ];
});

// --- who may open / use the pages -------------------------------------------------------------------------------

test('guests are sent to login on every transfer route', function () {
    $this->get(route('ims.transfers.create'))->assertRedirect(route('login'));
    $this->post(route('ims.transfers.review'), [])->assertRedirect(route('login'));
    $this->post(route('ims.transfers.store'), [])->assertRedirect(route('login'));
    $this->getJson(route('ims.transfers.doc-check', ['ims_doc_no' => 'X']))->assertUnauthorized();
});

test('without ims.manage (scientist, student, ADMIN) or without a branch every transfer route is 403 and nothing is written', function () {
    $before = ($this->written)();
    $rows = [($this->row)($this->lotA, 1)];

    $noBranch = labManagerUser(['lab_id' => null]);
    foreach ([scientistUser(['lab_id' => $this->lab->id]), studentUser(['lab_id' => $this->lab->id]), adminUser(['lab_id' => $this->lab->id]), $noBranch] as $user) {
        $this->actingAs($user)->get(route('ims.transfers.create'))->assertForbidden();
        $this->actingAs($user)->post(route('ims.transfers.review'), ['ims_doc_no' => 'WD-1', 'rows' => $rows])->assertForbidden();
        $this->actingAs($user)->post(route('ims.transfers.store'), ['ims_doc_no' => 'WD-1', 'rows' => $rows, 'token' => str_repeat('a', 40)])->assertForbidden();
        $this->actingAs($user)->getJson(route('ims.transfers.doc-check', ['ims_doc_no' => 'WD-1']))->assertForbidden();
    }

    expect(($this->written)())->toBe($before);
});

test('the fill-in page lists only this branch\'s lots with stock, flags the kinds the user may not move, and offers only the branch\'s places', function () {
    $emptied = makeImsLot($this->lab->id, $this->chemA, '1', '500', ['qty_remaining_base' => '0.000000']);
    $foreign = makeImsLot($this->otherLab->id, $this->chemA, '2', '500');

    $response = $this->actingAs($this->chemKeeper)->get(route('ims.transfers.create'))->assertOk();

    $lots = collect($response->viewData('lots'))->keyBy('ulid');
    expect($lots->has($this->lotA->ulid))->toBeTrue()
        ->and($lots->has($this->lotS->ulid))->toBeTrue()
        ->and($lots->has($emptied->ulid))->toBeFalse()
        ->and($lots->has($foreign->ulid))->toBeFalse()
        ->and($lots[$this->lotA->ulid]['movable'])->toBeTrue()
        ->and($lots[$this->lotS->ulid]['movable'])->toBeFalse()
        ->and($lots[$this->lotA->ulid]['packs_left'])->toBe('4');

    $places = collect($response->viewData('locations'))->pluck('ulid');
    expect($places->contains($this->location->ulid))->toBeTrue()->and($places->contains($this->otherLocation->ulid))->toBeFalse();
});

// --- the happy path ---------------------------------------------------------------------------------------------

test('one paper document with several rows moves every row: lot balances drop, one container per pack, RECEIVE in the ledger, ISSUE movements carry the document number, ONE audit row', function () {
    ($this->move)($this->manager, [($this->row)($this->lotA, 2), ($this->row)($this->lotB, 1)], 'WD-69-0042', 'ตามใบกระดาษ')
        ->assertRedirect(route('ims.lots.index'))
        ->assertSessionHas('status', __('ims_transfer.saved', ['count' => 2]))
        ->assertSessionHas('label_container_ids');

    expect((float) $this->lotA->fresh()->qty_remaining_base)->toEqual(1000.0)
        ->and((float) $this->lotB->fresh()->qty_remaining_base)->toEqual(500.0);

    // Whole packs -> one bottle per pack, each with its own label.
    $a = Container::where('item_id', $this->chemA->id)->get();
    $b = Container::where('item_id', $this->chemB->id)->get();
    expect($a)->toHaveCount(2)
        ->and($a->every(fn ($c) => (float) $c->remaining_qty_base === 500.0 && $c->ims_lot_id === $this->lotA->id && $c->location_id === $this->location->id))->toBeTrue()
        ->and($b)->toHaveCount(1)
        ->and((float) $b->first()->remaining_qty_base)->toEqual(250.0);

    $ledger = StockLedger::whereIn('container_id', $a->pluck('id')->merge($b->pluck('id')))->get();
    expect($ledger)->toHaveCount(3)
        ->and($ledger->every(fn ($row) => $row->txn_type === 'RECEIVE' && $row->remark === 'WD-69-0042'))->toBeTrue();

    $issueA = ImsMovement::where('ims_lot_id', $this->lotA->id)->where('type', 'ISSUE')->firstOrFail();
    $issueB = ImsMovement::where('ims_lot_id', $this->lotB->id)->where('type', 'ISSUE')->firstOrFail();
    expect((float) $issueA->qty_base)->toEqual(1000.0)
        ->and((float) $issueA->balance_after_base)->toEqual(1000.0)
        ->and($issueA->ref_doc_no)->toBe('WD-69-0042')
        ->and($issueA->remark)->toBe('ตามใบกระดาษ')
        ->and((float) $issueB->qty_base)->toEqual(250.0)
        ->and($issueB->ref_doc_no)->toBe('WD-69-0042');

    $audits = AuditLog::where('action', 'IMS_TRANSFER_BATCH')->get();
    expect($audits)->toHaveCount(1)
        ->and($audits->first()->user_id)->toBe($this->manager->id)
        ->and($audits->first()->new_value['doc_no'])->toBe('WD-69-0042')
        ->and($audits->first()->new_value['row_count'])->toBe(2);
});

test('a lot with no recorded pack size counts one pack as one base unit and becomes one bulk container', function () {
    $bulk = makeImsLot($this->lab->id, $this->chemA, '100', null);   // 100 g, no pack size

    ($this->move)($this->manager, [($this->row)($bulk, 40)])->assertRedirect(route('ims.lots.index'));

    expect((float) $bulk->fresh()->qty_remaining_base)->toEqual(60.0);
    $container = Container::where('ims_lot_id', $bulk->id)->firstOrFail();
    expect((float) $container->remaining_qty_base)->toEqual(40.0);
});

test('a paper document that lists both kinds is moved in one go by a manager who keeps both', function () {
    ($this->move)($this->manager, [($this->row)($this->lotA, 1), ($this->row)($this->lotS, 2)])->assertRedirect(route('ims.lots.index'));

    expect((float) $this->lotA->fresh()->qty_remaining_base)->toEqual(1500.0)
        ->and((float) $this->lotS->fresh()->qty_remaining_base)->toEqual(200.0)
        ->and(AuditLog::where('action', 'IMS_TRANSFER_BATCH')->count())->toBe(1);
});

test('the review page writes nothing and shows the balance each lot will have left', function () {
    $before = ($this->written)();

    $response = $this->actingAs($this->manager)->post(route('ims.transfers.review'), [
        'ims_doc_no' => 'WD-REV', 'rows' => [($this->row)($this->lotA, 2), ($this->row)($this->lotA, 1)],
    ])->assertOk();

    $lines = $response->viewData('lines');
    expect($lines[0]['packs'])->toBe('2')->and($lines[0]['qty'])->toBe('1000')->and($lines[0]['remaining_after'])->toBe('1000')
        ->and($lines[1]['remaining_after'])->toBe('500')   // the same lot twice: the second row sees the first
        ->and($response->viewData('token'))->toHaveLength(40);
    $response->assertSee(__('ims_transfer.irreversible'));

    expect(($this->written)())->toBe($before);
});

// --- mixed kinds / per-row permission ---------------------------------------------------------------------------

test('a document mixing two kinds posted by the keeper of only one: 403 for the whole request, on review AND on store, and nothing is written', function () {
    $before = ($this->written)();
    $mixed = [($this->row)($this->lotA, 1), ($this->row)($this->lotS, 1)];
    $token = ($this->review)($this->chemKeeper, [($this->row)($this->lotA, 1)]);   // a legitimate token

    $this->actingAs($this->chemKeeper)->post(route('ims.transfers.review'), ['ims_doc_no' => 'WD-MIX', 'rows' => $mixed])->assertForbidden();
    ($this->store)($this->chemKeeper, $mixed, $token, 'WD-MIX')->assertForbidden();
    // Order of the rows must not matter either.
    ($this->store)($this->chemKeeper, array_reverse($mixed), $token, 'WD-MIX')->assertForbidden();

    expect(($this->written)())->toBe($before);
});

test('a keeper with no kinds can open the page but every row is forbidden', function () {
    $none = auditorUser(['lab_id' => $this->lab->id, 'itemTypes' => []]);
    $before = ($this->written)();

    $this->actingAs($none)->get(route('ims.transfers.create'))->assertOk();
    $this->actingAs($none)->post(route('ims.transfers.review'), ['ims_doc_no' => 'WD-N', 'rows' => [($this->row)($this->lotA, 1)]])->assertForbidden();

    expect(($this->written)())->toBe($before);
});

test('a lot of another branch posted by name is 403 (tampered form), not a validation message', function () {
    $foreign = makeImsLot($this->otherLab->id, $this->chemA, '2', '500');

    $this->actingAs($this->manager)->post(route('ims.transfers.review'), [
        'ims_doc_no' => 'WD-F', 'rows' => [['lot' => $foreign->ulid, 'qty' => '1', 'location' => $this->location->ulid]],
    ])->assertForbidden();

    expect((float) $foreign->fresh()->qty_remaining_base)->toEqual(1000.0);
});

test('a lot that does not exist is a validation error on that row', function () {
    $this->actingAs($this->manager)->post(route('ims.transfers.review'), [
        'ims_doc_no' => 'WD-NONE', 'rows' => [['lot' => str_repeat('Z', 26), 'qty' => '1', 'location' => $this->location->ulid]],
    ])->assertRedirect(route('ims.transfers.create'))->assertSessionHasErrors('rows.0.lot');
});

// --- rollback ---------------------------------------------------------------------------------------------------

test('if any row fails the whole document is rolled back: the service call throws and no lot, container, ledger, movement or audit row survives', function () {
    $before = ($this->written)();
    $rows = [
        ['lot' => $this->lotA, 'packs' => '2', 'qty' => '1000.000000', 'location' => $this->location],
        ['lot' => $this->lotB, 'packs' => '99', 'qty' => '24750.000000', 'location' => $this->location],   // far more than the lot holds
    ];

    expect(fn () => app(ImsBatchTransferService::class)->transfer($this->manager, 'WD-RB', null, $rows, 'key-rollback'))
        ->toThrow(ImsException::class);

    expect(($this->written)())->toBe($before);
});

test('a row that is another kind than the user keeps rolls back the rows before it (service level, tampered rows)', function () {
    $before = ($this->written)();
    $rows = [
        ['lot' => $this->lotA, 'packs' => '2', 'qty' => '1000.000000', 'location' => $this->location],
        ['lot' => $this->lotS, 'packs' => '1', 'qty' => '100.000000', 'location' => $this->location],
    ];

    expect(fn () => app(ImsBatchTransferService::class)->transfer($this->chemKeeper, 'WD-RB2', null, $rows, 'key-rollback-2'))
        ->toThrow(AuthorizationException::class);

    expect(($this->written)())->toBe($before);
});

test('a row into another branch\'s place rolls back the rows before it (service level)', function () {
    $before = ($this->written)();
    $rows = [
        ['lot' => $this->lotA, 'packs' => '1', 'qty' => '500.000000', 'location' => $this->location],
        ['lot' => $this->lotB, 'packs' => '1', 'qty' => '250.000000', 'location' => $this->otherLocation],
    ];

    expect(fn () => app(ImsBatchTransferService::class)->transfer($this->manager, 'WD-RB3', null, $rows, 'key-rollback-3'))
        ->toThrow(ImsException::class);

    expect(($this->written)())->toBe($before);
});

// --- whole packs only / limits ----------------------------------------------------------------------------------

test('the quantity must be a whole number of packs: 0, negative, decimals, text, blanks and leading zeros are refused and nothing is written', function (string $qty) {
    $before = ($this->written)();

    $this->actingAs($this->manager)->post(route('ims.transfers.review'), [
        'ims_doc_no' => 'WD-Q', 'rows' => [['lot' => $this->lotA->ulid, 'qty' => $qty, 'location' => $this->location->ulid]],
    ])->assertRedirect(route('ims.transfers.create'))->assertSessionHasErrors('rows.0.qty');

    $token = str_repeat('t', 40);
    $this->withSession(['ims_transfer.tokens.'.$token => time()])->actingAs($this->manager)->post(route('ims.transfers.store'), [
        'ims_doc_no' => 'WD-Q', 'rows' => [['lot' => $this->lotA->ulid, 'qty' => $qty, 'location' => $this->location->ulid]], 'token' => $token,
    ])->assertSessionHasErrors('rows.0.qty');

    expect(($this->written)())->toBe($before);
})->with(['zero' => '0', 'negative' => '-1', 'decimal' => '1.5', 'text' => 'abc', 'blank' => '', 'leading zero' => '01', 'exponent' => '1e3', 'decimal comma' => '1,5', 'lone comma' => ',', 'bad grouping' => '1,00']);

test('more packs than the lot holds is refused, also when two rows of the same lot add up to more than it holds', function () {
    $before = ($this->written)();

    $this->actingAs($this->manager)->post(route('ims.transfers.review'), [
        'ims_doc_no' => 'WD-X', 'rows' => [($this->row)($this->lotA, 5)],   // the lot holds 4 packs
    ])->assertSessionHasErrors('rows.0.qty');

    $this->actingAs($this->manager)->post(route('ims.transfers.review'), [
        'ims_doc_no' => 'WD-X', 'rows' => [($this->row)($this->lotA, 3), ($this->row)($this->lotA, 2)],   // 3 + 2 > 4
    ])->assertSessionHasErrors('rows.1.qty');

    // Exactly everything is fine.
    $this->actingAs($this->manager)->post(route('ims.transfers.review'), [
        'ims_doc_no' => 'WD-X', 'rows' => [($this->row)($this->lotA, 3), ($this->row)($this->lotA, 1)],
    ])->assertOk();

    expect(($this->written)())->toBe($before);
});

test('a lot with no pack size cannot be over-drawn either (1 pack = 1 base unit)', function () {
    $bulk = makeImsLot($this->lab->id, $this->chemA, '100', null);

    $this->actingAs($this->manager)->post(route('ims.transfers.review'), [
        'ims_doc_no' => 'WD-B', 'rows' => [($this->row)($bulk, 101)],
    ])->assertSessionHasErrors('rows.0.qty');
    $this->actingAs($this->manager)->post(route('ims.transfers.review'), [
        'ims_doc_no' => 'WD-B', 'rows' => [($this->row)($bulk, 100)],
    ])->assertOk();
});

test('a lot emptied while the form was open is a normal "no stock left" message on that row, not a 403', function () {
    $this->lotA->update(['qty_remaining_base' => '0.000000']);

    $this->actingAs($this->manager)->post(route('ims.transfers.review'), [
        'ims_doc_no' => 'WD-E', 'rows' => [($this->row)($this->lotA, 1)],
    ])->assertRedirect(route('ims.transfers.create'))->assertSessionHasErrors('rows.0.qty');
});

test('the document number is required and rows must exist', function () {
    $this->actingAs($this->manager)->post(route('ims.transfers.review'), ['ims_doc_no' => '', 'rows' => [($this->row)($this->lotA, 1)]])->assertSessionHasErrors('ims_doc_no');
    $this->actingAs($this->manager)->post(route('ims.transfers.review'), ['ims_doc_no' => 'WD-1', 'rows' => []])->assertSessionHasErrors('rows');
    $this->actingAs($this->manager)->post(route('ims.transfers.review'), ['ims_doc_no' => str_repeat('9', 65), 'rows' => [($this->row)($this->lotA, 1)]])->assertSessionHasErrors('ims_doc_no');
});

test('a storage place of another branch, or one that does not exist, is refused for that row', function () {
    $before = ($this->written)();

    $this->actingAs($this->manager)->post(route('ims.transfers.review'), [
        'ims_doc_no' => 'WD-L', 'rows' => [($this->row)($this->lotA, 1, $this->otherLocation)],
    ])->assertRedirect(route('ims.transfers.create'))->assertSessionHasErrors('rows.0.location');

    $this->actingAs($this->manager)->post(route('ims.transfers.review'), [
        'ims_doc_no' => 'WD-L', 'rows' => [['lot' => $this->lotA->ulid, 'qty' => '1', 'location' => str_repeat('Z', 26)]],
    ])->assertSessionHasErrors('rows.0.location');

    // The same checks guard the final save, with a real token.
    $token = ($this->review)($this->manager, [($this->row)($this->lotA, 1)]);
    ($this->store)($this->manager, [($this->row)($this->lotA, 1, $this->otherLocation)], $token)->assertSessionHasErrors('rows.0.location');

    expect(($this->written)())->toBe($before);
});

// --- double submit / token ---------------------------------------------------------------------------------------

test('double submit: the second save with the same token moves nothing again', function () {
    $rows = [($this->row)($this->lotA, 2)];
    $token = ($this->review)($this->manager, $rows);

    ($this->store)($this->manager, $rows, $token)->assertRedirect(route('ims.lots.index'));
    $after = ($this->written)();
    expect($after['a'])->toBe('1000.000000')->and($after['audit'])->toBe(1);

    ($this->store)($this->manager, $rows, $token)
        ->assertRedirect(route('ims.transfers.create'))
        ->assertSessionHasErrors(['transfer' => __('ims_transfer.error.token_invalid')]);

    expect(($this->written)())->toBe($after);
});

test('double submit while the token is still in the session but already claimed (two clicks racing) is refused with "already saved"', function () {
    $rows = [($this->row)($this->lotA, 2)];
    $token = ($this->review)($this->manager, $rows);
    $before = ($this->written)();

    Cache::add('ims_transfer:claim:'.hash('sha256', $token), 1, 60);   // the first click holds the claim

    ($this->store)($this->manager, $rows, $token)
        ->assertRedirect(route('ims.lots.index'))
        ->assertSessionHasErrors(['transfer' => __('ims_transfer.error.already_submitted')]);

    expect(($this->written)())->toBe($before);
});

test('double submit at service level: a second call with the same request key is refused after the first one saved', function () {
    $rows = [['lot' => $this->lotA, 'packs' => '1', 'qty' => '500.000000', 'location' => $this->location]];
    $service = app(ImsBatchTransferService::class);

    $service->transfer($this->manager, 'WD-DS', null, $rows, 'same-key');
    $after = ($this->written)();

    expect(fn () => $service->transfer($this->manager, 'WD-DS', null, $rows, 'same-key'))
        ->toThrow(ImsException::class, __('ims_transfer.error.already_submitted'));

    expect(($this->written)())->toBe($after);
});

test('a save refused by validation does not spend the token: once the lot is back the same form saves', function () {
    $rows = [($this->row)($this->lotA, 2)];
    $token = ($this->review)($this->manager, $rows);

    // Somebody else empties the lot between review and save: the row becomes a validation error, nothing moves.
    $this->lotA->update(['qty_remaining_base' => '0.000000']);
    ($this->store)($this->manager, $rows, $token)->assertSessionHasErrors('rows.0.qty');
    expect(Container::count())->toBe(0);

    $this->lotA->update(['qty_remaining_base' => '2000.000000']);
    ($this->store)($this->manager, $rows, $token)->assertRedirect(route('ims.lots.index'));
    expect((float) $this->lotA->fresh()->qty_remaining_base)->toEqual(1000.0);
});

test('a missing, malformed, unknown or expired token is refused and nothing moves', function () {
    $rows = [($this->row)($this->lotA, 2)];
    $before = ($this->written)();

    // missing / wrong length -> validation error on token
    $this->actingAs($this->manager)->post(route('ims.transfers.store'), ['ims_doc_no' => 'WD-T', 'rows' => $rows])->assertSessionHasErrors('token');
    $this->actingAs($this->manager)->post(route('ims.transfers.store'), ['ims_doc_no' => 'WD-T', 'rows' => $rows, 'token' => 'short'])->assertSessionHasErrors('token');

    // well-formed but never handed out by the review page
    ($this->store)($this->manager, $rows, str_repeat('x', 40), 'WD-T')
        ->assertRedirect(route('ims.transfers.create'))
        ->assertSessionHasErrors(['transfer' => __('ims_transfer.error.token_invalid')]);

    // handed out, then the session is gone (expired / logged out)
    $token = ($this->review)($this->manager, $rows);
    $this->flushSession();
    ($this->store)($this->manager, $rows, $token, 'WD-T')
        ->assertRedirect(route('ims.transfers.create'))
        ->assertSessionHasErrors(['transfer' => __('ims_transfer.error.token_invalid')]);

    expect(($this->written)())->toBe($before);
});

// --- document number: warnings, not blocks -----------------------------------------------------------------------

test('doc-check returns the lots already moved under the same document number, in the same branch only', function () {
    $foreign = makeImsLot($this->otherLab->id, $this->chemA, '2', '500');
    $g = Unit::where('code', 'g')->firstOrFail();
    // The other branch moved a lot under the very same number.
    ImsMovement::create(['ims_lot_id' => $foreign->id, 'type' => 'ISSUE', 'qty_base' => '500', 'balance_after_base' => '500', 'display_unit_id' => $g->id, 'ref_doc_no' => 'WD-DUP', 'created_by' => $this->manager->id]);
    // A RECEIVE movement carrying a number is not a move into working stock.
    ImsMovement::create(['ims_lot_id' => $this->lotS->id, 'type' => 'RECEIVE', 'qty_base' => '400', 'balance_after_base' => '400', 'display_unit_id' => $g->id, 'ref_doc_no' => 'WD-RECV', 'created_by' => $this->manager->id]);

    ($this->move)($this->manager, [($this->row)($this->lotA, 1)], 'WD-DUP');

    $ask = fn ($user, string $doc, array $lots) => $this->actingAs($user)->getJson(route('ims.transfers.doc-check', ['ims_doc_no' => $doc, 'lots' => $lots]))->assertOk();

    $ask($this->manager, 'WD-DUP', [$this->lotA->ulid, $this->lotB->ulid, $foreign->ulid])
        ->assertExactJson(['used' => true, 'lots' => [$this->lotA->ulid]]);
    $ask($this->manager, 'WD-DUP', [])->assertExactJson(['used' => true, 'lots' => []]);
    $ask($this->manager, 'WD-OTHER', [$this->lotA->ulid])->assertExactJson(['used' => false, 'lots' => []]);
    $ask($this->manager, 'WD-RECV', [$this->lotS->ulid])->assertExactJson(['used' => false, 'lots' => []]);

    // The other branch's manager sees only their own branch's history under that number.
    $otherManager = labManagerUser(['lab_id' => $this->otherLab->id]);
    $ask($otherManager, 'WD-DUP', [$this->lotA->ulid, $foreign->ulid])->assertExactJson(['used' => true, 'lots' => [$foreign->ulid]]);
    $ask($otherManager, 'WD-ONLY-HERE', [$foreign->ulid])->assertExactJson(['used' => false, 'lots' => []]);
});

test('doc-check validates its input', function () {
    $this->actingAs($this->manager)->getJson(route('ims.transfers.doc-check'))->assertUnprocessable();
    $this->actingAs($this->manager)->getJson(route('ims.transfers.doc-check', ['ims_doc_no' => 'X', 'lots' => ['short']]))->assertUnprocessable();
});

test('review warns about a lot already moved under the same document number but never blocks the second move', function () {
    ($this->move)($this->manager, [($this->row)($this->lotA, 1)], 'WD-REPEAT');

    $response = $this->actingAs($this->manager)->post(route('ims.transfers.review'), [
        'ims_doc_no' => 'WD-REPEAT', 'rows' => [($this->row)($this->lotA, 1), ($this->row)($this->lotB, 1)],
    ])->assertOk();

    $lines = $response->viewData('lines');
    expect($lines[0]['dup'])->toBeTrue()->and($lines[1]['dup'])->toBeFalse();
    $response->assertSee(__('ims_transfer.lot_already_moved_warning'))->assertSee(__('ims_transfer.review_dup_banner'));

    // A different number: no warning at all.
    $other = $this->actingAs($this->manager)->post(route('ims.transfers.review'), [
        'ims_doc_no' => 'WD-NEW', 'rows' => [($this->row)($this->lotA, 1)],
    ])->assertOk();
    expect($other->viewData('lines')[0]['dup'])->toBeFalse();
    $other->assertDontSee(__('ims_transfer.review_dup_banner'));

    // Confirming the repeat is allowed ("ตั้งใจย้ายเพิ่ม").
    ($this->move)($this->manager, [($this->row)($this->lotA, 1)], 'WD-REPEAT')->assertRedirect(route('ims.lots.index'));
    expect((float) $this->lotA->fresh()->qty_remaining_base)->toEqual(1000.0);
    expect(ImsMovement::where('ims_lot_id', $this->lotA->id)->where('ref_doc_no', 'WD-REPEAT')->count())->toBe(2);
});

test('a comma is accepted only as a thousands separator: "1,000" is read as 1000 packs', function () {
    $bulk = makeImsLot($this->lab->id, $this->chemA, '5000', null);   // 5000 base units, no pack size

    $response = $this->actingAs($this->manager)->post(route('ims.transfers.review'), [
        'ims_doc_no' => 'WD-K', 'rows' => [['lot' => $bulk->ulid, 'qty' => '1,000', 'location' => $this->location->ulid]],
    ])->assertOk();

    expect($response->viewData('lines')[0]['packs'])->toBe('1000');
});

test('at most 500 containers per document: several rows adding up to more are refused on key rows and nothing is written; exactly 500 passes', function () {
    $big1 = makeImsLot($this->lab->id, $this->chemA, '400', '1');
    $big2 = makeImsLot($this->lab->id, $this->chemB, '400', '1');
    $before = ($this->written)();

    $this->actingAs($this->manager)->post(route('ims.transfers.review'), [
        'ims_doc_no' => 'WD-MAX', 'rows' => [($this->row)($big1, 300), ($this->row)($big2, 201)],
    ])->assertRedirect(route('ims.transfers.create'))
        ->assertSessionHasErrors(['rows' => __('ims_transfer.error.too_many_containers', ['max' => 500, 'count' => 501])]);

    $token = ($this->review)($this->manager, [($this->row)($big1, 300), ($this->row)($big2, 200)]);   // exactly 500 -> review passes
    ($this->store)($this->manager, [($this->row)($big1, 300), ($this->row)($big2, 201)], $token, 'WD-MAX')
        ->assertSessionHasErrors('rows');

    expect(($this->written)())->toBe($before);
});

test('the service itself refuses more than 500 containers before writing anything', function () {
    $big = makeImsLot($this->lab->id, $this->chemA, '600', '1');
    $before = ($this->written)();
    $rows = [['lot' => $big, 'packs' => '501', 'qty' => '501.000000', 'location' => $this->location]];

    expect(fn () => app(ImsBatchTransferService::class)->transfer($this->manager, 'WD-MAX2', null, $rows, 'key-max'))
        ->toThrow(ImsException::class, __('ims_transfer.error.too_many_containers', ['max' => 500, 'count' => 501]));

    expect(($this->written)())->toBe($before);
});

test('rows are processed in item/lot id order, but an error message still names the row number as typed', function () {
    // typed order: row 1 = lot B (later item), row 2 = lot A (earlier item).
    $ok = ['lot' => $this->lotB, 'packs' => '1', 'qty' => '250.000000', 'location' => $this->location];
    $okA = ['lot' => $this->lotA, 'packs' => '1', 'qty' => '500.000000', 'location' => $this->location];
    app(ImsBatchTransferService::class)->transfer($this->manager, 'WD-ORD', null, [$ok, $okA], 'key-order-1');

    $a = ImsMovement::where('ims_lot_id', $this->lotA->id)->where('type', 'ISSUE')->firstOrFail();
    $b = ImsMovement::where('ims_lot_id', $this->lotB->id)->where('type', 'ISSUE')->firstOrFail();
    expect($a->id)->toBeLessThan($b->id);   // A (typed second) was handled first

    // Now row 2 (lot A, processed first) is the bad one: the message says row 2, not row 1.
    $badA = ['lot' => $this->lotA, 'packs' => '99', 'qty' => '49500.000000', 'location' => $this->location];
    try {
        app(ImsBatchTransferService::class)->transfer($this->manager, 'WD-ORD2', null, [$ok, $badA], 'key-order-2');
        $this->fail('expected ImsException');
    } catch (ImsException $e) {
        expect($e->getMessage())->toContain(__('ims_transfer.error.row', ['row' => 2, 'message' => '']));
    }
});
