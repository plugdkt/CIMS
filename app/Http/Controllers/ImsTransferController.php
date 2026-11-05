<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Exceptions\ImsException;
use App\Domain\Inventory\Services\ImsBatchTransferService;
use App\Domain\Inventory\Services\WorkingStockDestinations;
use App\Http\Requests\ImsTransferDocCheckRequest;
use App\Http\Requests\ImsTransferReviewRequest;
use App\Http\Requests\ImsTransferStoreRequest;
use App\Models\ImsLot;
use App\Models\ImsMovement;
use App\Models\Location;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * ITM-2610-062: move many IMS lots into working stock against ONE paper requisition from the university store.
 * create (fill in) -> review (summary, nothing written) -> store (one transaction). The money/stock rules live in
 * {@see ImsBatchTransferService}; this class only carries the form between the three steps.
 */
final class ImsTransferController extends Controller
{
    /** Most lots offered in the picker (the page searches them in the browser). */
    private const LOT_LIMIT = 2000;

    /** How long a one-time form token (and the "this form was saved" claim) lives. */
    private const TOKEN_TTL_SECONDS = 7200;

    private const LAST_LOCATION_TTL_DAYS = 90;

    public function create(Request $request, WorkingStockDestinations $destinations): View
    {
        $this->authorize('transferAny', ImsLot::class);

        /** @var User $user */
        $user = $request->user();
        $labId = (int) $user->lab_id;

        $lots = $this->branchLots($user, $labId);
        $locations = $destinations->forType($labId);

        // The user's last chosen storage place (only if it is still one of this branch's places).
        $lastId = Cache::get($this->lastLocationKey($user));
        $defaultLocation = $locations->firstWhere('id', is_numeric($lastId) ? (int) $lastId : 0)->ulid ?? '';

        // Rows: what the user just typed (after a validation error), or the draft when coming back from the review page.
        /** @var array<string, mixed> $draft */
        $draft = $request->boolean('draft') ? (array) $request->session()->get('ims_transfer.draft', []) : [];
        $rows = old('rows', $draft['rows'] ?? []);
        $rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
        $rows = array_map(fn (array $r) => [
            'lot' => (string) ($r['lot'] ?? ''),
            'qty' => (string) ($r['qty'] ?? ''),
            'location' => (string) ($r['location'] ?? ''),
        ], $rows);

        return view('ims.transfers.create', [
            'lots' => $lots->map(fn (ImsLot $lot) => $this->lotPayload($lot, $user))->values()->all(),
            'truncated' => $lots->count() >= self::LOT_LIMIT,
            'locations' => $locations->map(fn (Location $l) => ['ulid' => $l->ulid, 'label' => $l->code.' — '.$l->name])->values()->all(),
            'defaultLocation' => $defaultLocation,
            'rows' => $rows,
            'docNo' => $this->text(old('ims_doc_no', $draft['ims_doc_no'] ?? '')),
            'remark' => $this->text(old('remark', $draft['remark'] ?? '')),
        ]);
    }

    public function review(ImsTransferReviewRequest $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $docNo = (string) $request->validated('ims_doc_no');
        $remark = $request->validated('remark');

        // Warn (never block) about a lot already moved under this very document number.
        $moved = $this->lotsMovedUnderDoc($docNo, (int) $user->lab_id, array_map(fn (array $r) => $r['lot']->id, $request->rows()));

        $lines = [];
        /** @var array<int, numeric-string> $running */
        $running = [];
        foreach ($request->rows() as $row) {
            $lot = $row['lot'];
            $running[$lot->id] = bcadd($running[$lot->id] ?? '0', $row['qty'], 6);
            $pack = $lot->pack_size_base;
            $hasPack = $pack !== null && bccomp($pack, '0', 6) > 0;

            $lines[] = [
                'code' => $lot->item?->item_code,
                'name' => $lot->item?->name_th,
                'lot_no' => $lot->lot_no,
                'qty' => $this->trim($row['qty']),
                'unit' => $lot->item?->baseUnit?->code,
                'pack' => $hasPack ? $this->trim((string) $pack) : null,
                'packs' => $row['packs'],
                'remaining_after' => $this->trim(bcsub($lot->qty_remaining_base, $running[$lot->id], 6)),
                'location' => $row['location']->code.' — '.$row['location']->name,
                'dup' => in_array($lot->id, $moved, true),
            ];
        }

        // Remember the form so "edit" brings it back, and hand out the one-time token for the save.
        $token = Str::random(40);
        $request->session()->put('ims_transfer.tokens.'.$token, now()->getTimestamp());
        $this->pruneTokens($request);
        $request->session()->put('ims_transfer.draft', [
            'ims_doc_no' => $docNo,
            'remark' => $remark,
            'rows' => array_map(fn (array $r) => ['lot' => $r['lot']->ulid, 'qty' => $r['packs'], 'location' => $r['location']->ulid], $request->rows()),
        ]);

        return view('ims.transfers.review', [
            'docNo' => $docNo,
            'remark' => $remark,
            'lines' => $lines,
            'rows' => $request->session()->get('ims_transfer.draft.rows'),
            'token' => $token,
        ]);
    }

    public function store(ImsTransferStoreRequest $request, ImsBatchTransferService $service): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = (string) $request->validated('token');
        $session = $request->session();

        // The token must be one this session was given at the review step, and can be spent only once.
        if (! $session->has('ims_transfer.tokens.'.$token)) {
            return redirect()->route('ims.transfers.create')->withErrors(['transfer' => __('ims_transfer.error.token_invalid')]);
        }
        if (! Cache::add('ims_transfer:claim:'.hash('sha256', $token), 1, self::TOKEN_TTL_SECONDS)) {
            return redirect()->route('ims.lots.index')->withErrors(['transfer' => __('ims_transfer.error.already_submitted')]);
        }

        try {
            $result = $service->transfer(
                $user,
                (string) $request->validated('ims_doc_no'),
                $request->filled('remark') ? (string) $request->input('remark') : null,
                $request->rows(),
                hash('sha256', $token),
            );
        } catch (ImsException $e) {
            // Nothing was saved: let the same form be tried again after the user fixes it.
            Cache::forget('ims_transfer:claim:'.hash('sha256', $token));

            return redirect()->route('ims.transfers.create', ['draft' => 1])->withErrors(['transfer' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Cache::forget('ims_transfer:claim:'.hash('sha256', $token));

            throw $e;
        }

        $session->forget('ims_transfer.tokens.'.$token);
        $session->forget('ims_transfer.draft');

        $last = $request->rows()[array_key_last($request->rows())]['location'] ?? null;
        if ($last !== null) {
            Cache::put($this->lastLocationKey($user), $last->id, now()->addDays(self::LAST_LOCATION_TTL_DAYS));
        }

        $redirect = redirect()->route('ims.lots.index')->with('status', __('ims_transfer.saved', ['count' => $result['row_count']]));
        if ($result['container_ids'] !== []) {
            $redirect->with('label_container_ids', implode(',', $result['container_ids']));
        }

        return $redirect;
    }

    /**
     * JSON while the number / rows are being typed. `lots` = ULIDs (of the posted `lots[]`) that were ALREADY moved under
     * this document number in the user's branch (a warning, never a block); `used` = the number has any move at all.
     */
    public function docCheck(ImsTransferDocCheckRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $labId = (int) $user->lab_id;
        $docNo = trim((string) $request->validated('ims_doc_no'));

        /** @var list<string> $ulids */
        $ulids = array_values((array) ($request->validated('lots') ?? []));
        $byId = $ulids === [] ? collect() : ImsLot::query()->where('lab_id', $labId)->whereIn('ulid', $ulids)->pluck('ulid', 'id');
        $movedIds = $this->lotsMovedUnderDoc($docNo, $labId, array_values($byId->keys()->map(fn ($id) => (int) $id)->all()));

        return response()->json([
            'used' => ImsMovement::query()->where('ref_doc_no', $docNo)->where('type', 'ISSUE')->whereHas('lot', fn ($l) => $l->where('lab_id', $labId))->exists(),
            'lots' => array_values(array_filter(array_map(fn (int $id) => $byId->get($id), $movedIds))),
        ]);
    }

    /**
     * Of these lots, the ones that already have an ISSUE movement under this document number (same branch).
     *
     * @param  list<int>  $lotIds
     * @return list<int>
     */
    private function lotsMovedUnderDoc(string $docNo, int $labId, array $lotIds): array
    {
        if ($lotIds === []) {
            return [];
        }

        return array_values(array_map('intval', ImsMovement::query()
            ->where('ref_doc_no', $docNo)
            ->where('type', 'ISSUE')
            ->whereIn('ims_lot_id', $lotIds)
            ->whereHas('lot', fn ($l) => $l->where('lab_id', $labId))
            ->distinct()
            ->pluck('ims_lot_id')
            ->all()));
    }

    /**
     * Every lot of the branch with stock left (the paper document may list any kind). Each carries `movable`: only the
     * kinds this user looks after can be moved by them; the others show read-only on the page.
     *
     * @return \Illuminate\Support\Collection<int, ImsLot>
     */
    private function branchLots(User $user, int $labId): \Illuminate\Support\Collection
    {
        return ImsLot::query()
            ->with(['item.category', 'item.baseUnit', 'location'])
            ->where('lab_id', $labId)
            ->where('qty_remaining_base', '>', 0)
            ->orderBy('item_id')
            ->orderByRaw('expiry_date is null')
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->limit(self::LOT_LIMIT)
            ->get();
    }

    /** @return array<string, string|bool|null> */
    private function lotPayload(ImsLot $lot, User $user): array
    {
        $pack = $lot->pack_size_base;
        $hasPack = $pack !== null && bccomp($pack, '0', 6) > 0;

        return [
            'ulid' => $lot->ulid,
            'code' => (string) $lot->item?->item_code,
            'name' => (string) $lot->item?->name_th,
            'lot' => (string) ($lot->lot_no ?? ''),
            'expiry' => $lot->expiry_date?->format('d/m/Y'),
            'remaining' => $this->trim($lot->qty_remaining_base),
            // Whole packs still in the lot (no pack size: 1 pack = 1 base unit).
            'packs_left' => bcdiv($lot->qty_remaining_base, $hasPack ? (string) $pack : '1', 0),
            'unit' => (string) $lot->item?->baseUnit?->code,
            'pack' => $hasPack ? $this->trim((string) $pack) : null,
            'place' => $lot->location?->name,
            'movable' => $user->can('transfer', $lot),
        ];
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function trim(string $value): string
    {
        return str_contains($value, '.') ? (rtrim(rtrim($value, '0'), '.') ?: '0') : $value;
    }

    private function lastLocationKey(User $user): string
    {
        return 'ims_transfer:last_location:'.$user->id;
    }

    private function pruneTokens(Request $request): void
    {
        /** @var array<string, int> $tokens */
        $tokens = (array) $request->session()->get('ims_transfer.tokens', []);
        if (count($tokens) > 10) {
            asort($tokens);
            $request->session()->put('ims_transfer.tokens', array_slice($tokens, -10, null, true));
        }
    }
}
