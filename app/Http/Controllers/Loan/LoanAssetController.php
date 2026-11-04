<?php

declare(strict_types=1);

namespace App\Http\Controllers\Loan;

use App\Domain\Loan\Exceptions\LoanDomainException;
use App\Domain\Loan\Services\LoanLedgerHasher;
use App\Domain\Loan\Services\LoanLedgerService;
use App\Domain\Loan\Services\LoanNotifier;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loan\LoanAssetAdjustRequest;
use App\Http\Requests\Loan\LoanAssetListRequest;
use App\Http\Requests\Loan\LoanAssetRetireRequest;
use App\Http\Requests\Loan\LoanAssetStoreRequest;
use App\Http\Requests\Loan\LoanAssetUpdateRequest;
use App\Http\Requests\Loan\LoanAssetVerifyRequest;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Lab;
use App\Models\LoanAsset;
use App\Models\Location;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * ITM-2610-051 step 1: the register of lending assets. Thin: validation in the Form Requests, access in
 * LoanAssetPolicy, every write in LoanLedgerService.
 */
final class LoanAssetController extends Controller
{
    public function index(LoanAssetListRequest $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $assets = LoanAsset::query()
            ->with(['item.baseUnit', 'lab', 'location'])
            ->when(! $user->hasRole('ADMIN'), fn ($q) => $q->where('lab_id', $user->lab_id))
            ->when($user->hasRole('ADMIN') && $request->filled('lab_id'), fn ($q) => $q->where('lab_id', (int) $request->query('lab_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->query('status')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = trim((string) $request->query('q'));
                $q->where(function ($w) use ($term) {
                    $w->where('asset_tag', 'like', "%{$term}%")
                        ->orWhereHas('item', fn ($i) => $i->where('name_th', 'like', "%{$term}%")
                            ->orWhere('name_en', 'like', "%{$term}%")
                            ->orWhere('item_code', 'like', "%{$term}%"));
                });
            })
            ->orderBy('lab_id')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('loans.assets.index', [
            'assets' => $assets,
            'labs' => $user->hasRole('ADMIN') ? Lab::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_th']) : collect(),
            'isAdmin' => $user->hasRole('ADMIN'),
        ]);
    }

    public function create(LoanAssetListRequest $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $term = trim((string) $request->query('q', ''));

        // Only items counted in whole pieces can be lent (BR-L01); the service re-checks on save.
        $items = $term === '' ? collect() : Item::query()
            ->where('is_active', true)
            ->whereHas('baseUnit', fn ($u) => $u->where('dimension', 'COUNT'))
            ->where(function ($q) use ($term) {
                $q->where('name_th', 'like', "%{$term}%")
                    ->orWhere('name_en', 'like', "%{$term}%")
                    ->orWhere('item_code', 'like', "%{$term}%");
            })
            ->orderBy('name_th')
            ->limit(30)
            ->get(['id', 'item_code', 'name_th']);

        return view('loans.assets.create', [
            'items' => $items,
            'term' => $term,
            'isAdmin' => $user->hasRole('ADMIN'),
            'labs' => $user->hasRole('ADMIN') ? Lab::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_th']) : collect(),
            'ownLab' => $user->lab,
            'locations' => Location::query()
                ->when(! $user->hasRole('ADMIN'), fn ($q) => $q->where('lab_id', $user->lab_id))
                ->whereNotNull('lab_id')
                ->with('lab:id,code')
                ->orderBy('lab_id')->orderBy('name')
                ->limit(500)
                ->get(['id', 'lab_id', 'code', 'name']),
        ]);
    }

    public function store(LoanAssetStoreRequest $request, LoanLedgerService $ledger): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        try {
            $asset = $ledger->register($user, [
                'lab_id' => (int) $request->labId(),
                'item_id' => (int) $data['item_id'],
                'tracking_mode' => (string) $data['tracking_mode'],
                'qty' => $data['tracking_mode'] === 'UNIT' ? 1 : (string) $data['qty'],
                'asset_tag' => $data['asset_tag'] ?? null,
                'location_id' => isset($data['location_id']) ? (int) $data['location_id'] : null,
                'max_loan_days' => isset($data['max_loan_days']) ? (int) $data['max_loan_days'] : null,
                'replacement_value' => $data['replacement_value'] ?? null,
                'condition_note' => $data['condition_note'] ?? null,
            ]);
        } catch (LoanDomainException $e) {
            return back()->withInput()->withErrors(['asset' => $e->getMessage()]);
        }

        return redirect()->route('loans.assets.show', $asset)->with('status', __('loans.saved'));
    }

    public function show(LoanAsset $loanAsset): View
    {
        $this->authorize('viewRegistryEntry', $loanAsset);

        /** @var User $user */
        $user = auth()->user();

        $loanAsset->load(['item.baseUnit', 'lab', 'location']);

        // Possible approvers (approvals.md K): another active holder of loan.asset_approve in this asset's branch
        // (scientists and warehouse managers; never ADMIN). Same rule LoanLedgerService::assertApprover enforces.
        $approvers = User::query()
            ->where('is_active', true)
            ->where('id', '!=', $user->id)
            ->whereHas('roles.permissions', fn ($q) => $q->where('code', 'loan.asset_approve'))
            ->whereNotNull('lab_id')
            ->where('lab_id', $loanAsset->lab_id)
            ->orderBy('full_name')
            ->get(['id', 'full_name']);

        return view('loans.assets.show', [
            'asset' => $loanAsset,
            'movements' => $loanAsset->movements()->with(['creator:id,full_name', 'approver:id,full_name'])->get()->reverse()->values(),
            'approvers' => $approvers,
            'locations' => Location::where('lab_id', $loanAsset->lab_id)->orderBy('name')->get(['id', 'code', 'name']),
            'canSeeValue' => $user->can('seeReplacementValue', LoanAsset::class),
            'canVerify' => $user->can('verifyChain', LoanAsset::class),
        ]);
    }

    public function update(LoanAssetUpdateRequest $request, LoanAsset $loanAsset): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        $before = ['max_loan_days' => $loanAsset->max_loan_days, 'replacement_value' => $loanAsset->replacement_value];
        $loanAsset->update([
            'location_id' => $data['location_id'] ?? null,
            'max_loan_days' => $data['max_loan_days'] ?? null,
            'replacement_value' => $data['replacement_value'] ?? null,
            'condition_note' => $data['condition_note'] ?? null,
        ]);
        $after = ['max_loan_days' => $loanAsset->max_loan_days, 'replacement_value' => $loanAsset->replacement_value];

        // The two fields that change what a loan may do / what a loss is worth are audited (old -> new, no personal data).
        $norm = fn ($v) => $v === null ? null : (string) $v;
        $changed = array_keys(array_filter($after, fn ($v, $k) => $norm($v) !== $norm($before[$k]), ARRAY_FILTER_USE_BOTH));
        if ($changed !== []) {
            AuditLog::record(
                action: 'LOAN_ASSET_UPDATE',
                userId: $user->id,
                username: $user->username,
                entityType: LoanAsset::class,
                entityId: $loanAsset->id,
                oldValue: array_intersect_key($before, array_flip($changed)),
                newValue: array_intersect_key($after, array_flip($changed)),
            );
        }

        return redirect()->route('loans.assets.show', $loanAsset)->with('status', __('loans.saved'));
    }

    public function adjust(LoanAssetAdjustRequest $request, LoanAsset $loanAsset, LoanLedgerService $ledger, LoanNotifier $notifier): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        try {
            $ledger->adjust(
                $user,
                $loanAsset,
                (string) $data['direction'],
                (string) $data['qty'],
                (string) $data['note'],
                $data['external_ref'] ?? null,
                (int) $data['approved_by'],
            );
        } catch (LoanDomainException $e) {
            return back()->withInput()->withErrors(['adjust' => $e->getMessage()]);
        }

        // After the movement is saved: tell the named approver (a failure here cannot undo the saved adjustment).
        $notifier->approverNamed($user, $loanAsset, $data['direction'] === 'IN' ? 'adjust_in' : 'adjust_out', (int) $data['approved_by'], (string) $data['note']);

        return redirect()->route('loans.assets.show', $loanAsset)->with('status', __('loans.adjusted'));
    }

    public function retire(LoanAssetRetireRequest $request, LoanAsset $loanAsset, LoanLedgerService $ledger, LoanNotifier $notifier): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        try {
            $ledger->retire($user, $loanAsset, (string) $data['note'], (int) $data['approved_by']);
        } catch (LoanDomainException $e) {
            return back()->withInput()->withErrors(['retire' => $e->getMessage()]);
        }

        $notifier->approverNamed($user, $loanAsset, 'retire', (int) $data['approved_by'], (string) $data['note']);

        return redirect()->route('loans.assets.show', $loanAsset)->with('status', __('loans.retired'));
    }

    public function verify(LoanAssetVerifyRequest $request, LoanAsset $loanAsset, LoanLedgerHasher $hasher): RedirectResponse
    {
        $result = $hasher->verifyChain($loanAsset->id);

        return redirect()->route('loans.assets.show', $loanAsset)->with(
            'status',
            $result['ok'] ? __('loans.verify_ok') : __('loans.verify_broken', ['id' => $result['broken_at']]),
        );
    }
}
