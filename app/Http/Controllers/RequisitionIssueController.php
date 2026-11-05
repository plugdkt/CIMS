<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Services\FefoContainerSelector;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\Requisition\Exceptions\ExcessiveIssueQuantityException;
use App\Domain\Requisition\Exceptions\InvalidRequisitionTransitionException;
use App\Domain\Requisition\Services\IssueService;
use App\Domain\Requisition\Services\ReturnService;
use App\Domain\Requisition\Services\ShopAvailability;
use App\Domain\Shared\ItemType;
use App\Domain\Shared\UnitConverter;
use App\Http\Requests\RequisitionIssueAllRequest;
use App\Models\Container;
use App\Models\IssueTransaction;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/** FR-RQ-09/10: dispensing against an APPROVED/PARTIALLY_ISSUED requisition, via the issue-all form (FEFO across containers). */
final class RequisitionIssueController extends Controller
{
    /**
     * Gated on "can issue OR can return", not just "can issue" — a fully ISSUED
     * requisition can no longer be issued against but can still be returned against
     * (BR-05), so this page must stay reachable for that case too.
     */
    public function create(
        Requisition $requisition,
        FefoContainerSelector $selector,
        ReturnService $returns,
        StockBalanceService $stockBalance,
        ShopAvailability $availability,
    ): View {
        /** @var User|null $user */
        $user = auth()->user();
        abort_unless($user !== null && ($user->can('issue', $requisition) || $user->can('return', $requisition)), 403);

        $requisition->load(['items.item', 'items.unit', 'requester']);

        // User-requested 2026-09-21: warn right where the actual dispensing happens
        // (not on the earlier review page) once an item's current balance is below
        // its reorder point — the person issuing is exactly who'd go restock it.
        $lines = $requisition->items->map(function (RequisitionItem $line) use ($selector, $returns, $stockBalance, $availability, $requisition, $user) {
            $item = $line->item()->firstOrFail();
            $balance = $stockBalance->currentBalance($item);

            return [
                'line' => $line,
                // User-requested 2026-09-23: remaining is against what was actually
                // approved, not what was requested, once a warehouse manager approves less.
                'remaining_base' => bcsub($line->approvedCeilingBase(), (string) ($line->qty_issued_base ?? '0'), 6),
                'returnable_base' => bcsub((string) ($line->qty_issued_base ?? '0'), (string) ($line->qty_returned_base ?? '0'), 6),
                'containers' => $selector->recommend($item),
                'issued_containers' => $returns->issuedContainersFor($line),
                'stock_balance' => $balance,
                // ITM-2610-062: only `working` (= `orderable`) can be dispensed; `ims` is shown for information. Nothing is
                // cut from IMS automatically any more: a stock keeper moves it by hand first (ims.transfers).
                'availability' => $availability->forItem($item, $requisition->lab_id),
                'low_stock' => $stockBalance->isBelowReorderPoint($item, $balance),
                // May this user go and move this item's kind of stock from IMS (deny by default)?
                'can_transfer' => $user->can('ims.manage') && $user->managesItemType(ItemType::forItem($item)),
            ];
        });

        return view('requisitions.issue', [
            'requisition' => $requisition,
            'lines' => $lines,
            'units' => Unit::orderBy('sort_order')->get(),
            'selector' => $selector,
            'canReturn' => auth()->user()?->can('return', $requisition) ?? false,
            // Who may collect the goods: the requester (default) or anyone else in the same branch.
            'receivers' => User::query()
                ->where('is_active', true)
                ->where(fn ($q) => $q->where('lab_id', $requisition->lab_id)->orWhere('id', $requisition->requester_id))
                ->orderBy('full_name')
                ->get(['id', 'full_name']),
        ]);
    }

    /**
     * One button for the whole requisition: every line that still needs dispensing is issued
     * in one submission, with no receiver signature/OTP.
     */
    public function storeAll(
        RequisitionIssueAllRequest $request,
        Requisition $requisition,
        IssueService $service,
        FefoContainerSelector $selector,
        UnitConverter $converter,
        ShopAvailability $availability,
    ): RedirectResponse {
        $data = $request->validated();

        /** @var User $issuer */
        $issuer = $request->user();

        // The requester collects by default; the issuer may name someone else from the same branch.
        $receiver = $requisition->requester()->firstOrFail();
        if (! empty($data['receiver_id']) && (int) $data['receiver_id'] !== $receiver->id) {
            $other = User::whereKey($data['receiver_id'])->where('is_active', true)->where('lab_id', $requisition->lab_id)->first();
            if ($other === null) {
                return back()->withErrors(['receiver_id' => __('requisitions.validation.receiver_invalid')])->withInput();
            }
            $receiver = $other;
        }

        // Controlled substances: the issuer must explicitly confirm the receiver is present and took them.
        $requisition->load('items.item');
        $hasControlled = $requisition->items->contains(function (RequisitionItem $l) use ($data) {
            $qty = $data['lines'][$l->id]['qty'] ?? null;

            return $l->item?->is_controlled === true && is_numeric($qty) && bccomp((string) $qty, '0', 6) > 0;
        });
        if ($hasControlled && empty($data['controlled_ack'])) {
            return back()->withErrors(['controlled_ack' => __('requisitions.validation.controlled_ack_required')])->withInput();
        }

        // No receiver signature/OTP any more: the ledger still gets a tamper-evident marker
        // tying this issue to the requisition, issuer, receiver and time.
        $signatureHash = hash('sha256', "ISSUE|{$requisition->id}|{$issuer->id}|{$receiver->id}|".now()->toISOString());
        $signatureImagePath = null;

        $done = 0;
        $errors = [];

        foreach ($requisition->items as $line) {
            $row = $data['lines'][$line->id] ?? null;
            if ($row === null || ! isset($row['qty']) || ! is_numeric($row['qty'])) {
                continue;
            }

            $unit = Unit::whereKey($row['unit_id'])->firstOrFail();
            $name = $line->item()->value('name_th') ?? '#'.$line->id;

            /** @var numeric-string $qty */
            $qty = (string) $row['qty'];
            if (bccomp($qty, '0', 6) <= 0) {
                continue;
            }

            $result = $this->issueLineAuto(
                $line,
                $requisition,
                $qty,
                $unit,
                $issuer,
                $receiver,
                $signatureHash,
                $signatureImagePath,
                $data['remark'] ?? null,
                $data['overage_approved_by'] ?? null,
                $service,
                $selector,
                $converter,
                $availability,
            );

            if ($result['error'] !== null) {
                $errors[] = $name.': '.$result['error'];

                continue;
            }
            $done++;
        }

        $redirect = redirect()->route('requisitions.issue.create', $requisition);
        if ($done > 0) {
            $redirect->with('status', __('requisitions.issue_all_recorded', ['count' => $done]));
        }
        if ($errors !== []) {
            $redirect->withErrors(['issue' => $errors]);
        } elseif ($done === 0) {
            $redirect->withErrors(['issue' => __('requisitions.validation.issue_all_nothing')]);
        }

        return $redirect;
    }

    /**
     * Dispenses one line from WORKING stock only, FEFO-allocated across containers (ITM-2610-062: nothing is cut from
     * IMS automatically any more). If working stock cannot cover the whole quantity the line is rejected whole: nothing
     * is issued for it, so the requisition keeps its status (APPROVED / PARTIALLY_ISSUED) and the line stays open.
     * Each line is its own transaction: a line that fails rolls back only its own containers, never other lines
     * (lines already issued stay issued, and the status already reflects them).
     * Used by {@see storeAll()} only.
     *
     * @param  numeric-string  $qty
     * @return array{error: ?string, message: ?string}
     */
    private function issueLineAuto(
        RequisitionItem $requisitionItem,
        Requisition $requisition,
        string $qty,
        Unit $unit,
        User $issuer,
        User $receiver,
        string $signatureHash,
        ?string $signatureImagePath,
        mixed $remark,
        mixed $overageApprovedBy,
        IssueService $service,
        FefoContainerSelector $selector,
        UnitConverter $converter,
        ShopAvailability $availability,
    ): array {
        $item = $requisitionItem->item()->firstOrFail();

        /** @var numeric-string $neededBase */
        $neededBase = $converter->toItemBase($item, $unit, $qty);
        if (bccomp($neededBase, $availability->forItem($item, $requisition->lab_id)['orderable'], 6) > 0) {
            return ['error' => __('working_stock.error.issue_working_short'), 'message' => null];
        }

        $containers = $selector->recommendExcludingExpired($item)->filter(function (Container $c) use ($requisition) {
            $labId = $c->location()->value('lab_id');

            return $labId === null || $labId === $requisition->lab_id;
        })->values();

        try {
            $transactions = DB::transaction(function () use ($service, $requisitionItem, $containers, $qty, $unit, $issuer, $receiver, $signatureHash, $signatureImagePath, $remark, $overageApprovedBy, $neededBase) {
                $transactions = $service->issueAcrossContainers(
                    $requisitionItem,
                    $containers,
                    $qty,
                    $unit,
                    $issuer,
                    $receiver,
                    $signatureHash,
                    $signatureImagePath,
                    is_string($remark) ? $remark : null,
                    is_numeric($overageApprovedBy) ? (int) $overageApprovedBy : null,
                );

                // The containers the selector found cannot cover it after all: undo this line completely.
                $issuedBase = array_reduce(
                    $transactions,
                    fn (string $sum, IssueTransaction $t) => bcadd($sum, $t->qty_issued_base, 6),
                    '0.000000',
                );
                if (bccomp($issuedBase, $neededBase, 6) < 0) {
                    throw new InsufficientStockException($transactions === []
                        ? (string) __('requisitions.validation.no_stock_available')
                        : (string) __('working_stock.error.issue_working_short'));
                }

                return $transactions;
            });
        } catch (InvalidRequisitionTransitionException|ExcessiveIssueQuantityException|InsufficientStockException $e) {
            // The failed line's in-memory copy may hold quantities that were rolled back.
            $requisitionItem->refresh();

            return ['error' => $e->getMessage(), 'message' => null];
        }

        return ['error' => null, 'message' => __('requisitions.issue_recorded_multi', ['containers' => count($transactions)])];
    }
}
