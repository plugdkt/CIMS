<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\ImsException;
use App\Domain\Inventory\Services\FefoContainerSelector;
use App\Domain\Inventory\Services\ImsService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\Requisition\Exceptions\ExcessiveIssueQuantityException;
use App\Domain\Requisition\Exceptions\InvalidRequisitionTransitionException;
use App\Domain\Requisition\Exceptions\InvalidSignatureImageException;
use App\Domain\Requisition\Services\IssueService;
use App\Domain\Requisition\Services\ReceiverOtpService;
use App\Domain\Requisition\Services\ReturnService;
use App\Domain\Requisition\Services\ShopAvailability;
use App\Domain\Requisition\Services\SignatureImageService;
use App\Domain\Shared\UnitConverter;
use App\Http\Requests\RequisitionAutoIssueRequest;
use App\Http\Requests\RequisitionIssueAllRequest;
use App\Http\Requests\RequisitionIssueRequest;
use App\Models\Container;
use App\Models\ImsLot;
use App\Models\IssueTransaction;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/** FR-RQ-09/10: dispensing against an APPROVED/PARTIALLY_ISSUED requisition, one container at a time. */
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
        $lines = $requisition->items->map(function (RequisitionItem $line) use ($selector, $returns, $stockBalance, $availability, $requisition) {
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
                // What the branch can actually hand out for this line: working stock plus IMS (cut automatically).
                'availability' => $availability->forItem($item, $requisition->lab_id),
                'low_stock' => $stockBalance->isBelowReorderPoint($item, $balance),
                // IMS lots with no storage place cannot be cut into working stock automatically.
                'unplaced_lots' => ImsLot::where('item_id', $item->id)
                    ->where('lab_id', $requisition->lab_id)
                    ->where('qty_remaining_base', '>', 0)
                    ->whereNull('location_id')
                    ->where(fn ($q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', now()->toDateString()))
                    ->orderBy('id')
                    ->get(),
            ];
        });

        return view('requisitions.issue', [
            'requisition' => $requisition,
            'lines' => $lines,
            'units' => Unit::orderBy('sort_order')->get(),
            'selector' => $selector,
            'canReturn' => auth()->user()?->can('return', $requisition) ?? false,
        ]);
    }

    public function store(
        RequisitionIssueRequest $request,
        Requisition $requisition,
        RequisitionItem $requisitionItem,
        IssueService $service,
        SignatureImageService $signatures,
        ReceiverOtpService $otp,
    ): RedirectResponse {
        $data = $request->validated();
        $container = Container::where('barcode', $data['barcode'])->firstOrFail();
        $unit = Unit::whereKey($data['unit_id'])->firstOrFail();

        /** @var User $issuer */
        $issuer = $request->user();
        $receiver = $requisition->requester()->firstOrFail();

        /** @var numeric-string $qtyIssued */
        $qtyIssued = (string) $data['qty_issued'];

        try {
            [$signatureHash, $signatureImagePath] = $this->resolveReceiverSignature($data, $receiver, $requisition, $signatures, $otp);
        } catch (InvalidSignatureImageException $e) {
            return back()->withErrors(['signature_image' => $e->getMessage()]);
        }

        if ($signatureHash === null) {
            return back()->withErrors(['otp_code' => __('requisitions.validation.otp_code_invalid')]);
        }

        try {
            $service->issue(
                $requisitionItem,
                $container,
                $qtyIssued,
                $unit,
                $issuer,
                $receiver,
                $signatureHash,
                $signatureImagePath,
                $data['remark'] ?? null,
                $data['overage_approved_by'] ?? null,
            );
        } catch (InvalidRequisitionTransitionException|ExcessiveIssueQuantityException|InsufficientStockException $e) {
            return back()->withErrors(['issue' => $e->getMessage()]);
        }

        return redirect()->route('requisitions.issue.create', $requisition)->with('status', __('requisitions.issue_recorded'));
    }

    /**
     * User-requested 2026-09-23: "ให้ระบบแนะนำ/จ่ายจากหลายขวดในคลิกเดียว" — one submission, one
     * total quantity, the FEFO-recommended containers filled in order automatically instead
     * of the issuer repeating {@see store()} once per container.
     */
    public function storeAuto(
        RequisitionAutoIssueRequest $request,
        Requisition $requisition,
        RequisitionItem $requisitionItem,
        IssueService $service,
        FefoContainerSelector $selector,
        SignatureImageService $signatures,
        ReceiverOtpService $otp,
        UnitConverter $converter,
        ImsService $ims,
        ShopAvailability $availability,
    ): RedirectResponse {
        $data = $request->validated();
        $unit = Unit::whereKey($data['unit_id'])->firstOrFail();

        /** @var User $issuer */
        $issuer = $request->user();
        $receiver = $requisition->requester()->firstOrFail();

        try {
            [$signatureHash, $signatureImagePath] = $this->resolveReceiverSignature($data, $receiver, $requisition, $signatures, $otp);
        } catch (InvalidSignatureImageException $e) {
            return back()->withErrors(['signature_image' => $e->getMessage()]);
        }

        if ($signatureHash === null) {
            return back()->withErrors(['otp_code' => __('requisitions.validation.otp_code_invalid')]);
        }

        /** @var numeric-string $qtyRequested */
        $qtyRequested = (string) $data['qty_issued'];

        $result = $this->issueLineAuto(
            $requisitionItem,
            $requisition,
            $qtyRequested,
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
            $ims,
            $availability,
        );

        if ($result['error'] !== null) {
            return back()->withErrors(['issue' => $result['error']]);
        }

        return redirect()->route('requisitions.issue.create', $requisition)->with('status', $result['message']);
    }

    /**
     * One button for the whole requisition: every line that still needs dispensing is issued
     * in one submission, with no receiver signature/OTP — instead of repeating {@see storeAuto()} per chemical.
     */
    public function storeAll(
        RequisitionIssueAllRequest $request,
        Requisition $requisition,
        IssueService $service,
        FefoContainerSelector $selector,
        UnitConverter $converter,
        ImsService $ims,
        ShopAvailability $availability,
    ): RedirectResponse {
        $data = $request->validated();

        /** @var User $issuer */
        $issuer = $request->user();
        $receiver = $requisition->requester()->firstOrFail();

        // No receiver signature/OTP any more: the ledger still gets a tamper-evident marker
        // tying this issue to the requisition, issuer, receiver and time.
        $signatureHash = hash('sha256', "ISSUE|{$requisition->id}|{$issuer->id}|{$receiver->id}|".now()->toISOString());
        $signatureImagePath = null;

        $requisition->load('items.item');
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
                $ims,
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
     * Dispenses one line: working stock first, the shortfall cut from this branch's IMS store
     * (whole bottles, soonest-expiring lot first), then FEFO-allocated across containers.
     * Only called once the receiver's signature is good, so a failed OTP never moves stock.
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
        ImsService $ims,
        ShopAvailability $availability,
    ): array {
        $item = $requisitionItem->item()->firstOrFail();

        try {
            /** @var numeric-string $neededBase */
            $neededBase = $converter->toItemBase($item, $unit, $qty);
            $ims->provisionWorkingStock(
                $item,
                $requisition->lab_id,
                $neededBase,
                $availability->forItem($item, $requisition->lab_id)['working'],
                $issuer,
                $requisition->doc_no,
            );
        } catch (ImsException $e) {
            return ['error' => $e->getMessage(), 'message' => null];
        }

        $containers = $selector->recommendExcludingExpired($item)->filter(function (Container $c) use ($requisition) {
            $labId = $c->location()->value('lab_id');

            return $labId === null || $labId === $requisition->lab_id;
        })->values();

        try {
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
        } catch (InvalidRequisitionTransitionException|ExcessiveIssueQuantityException|InsufficientStockException $e) {
            return ['error' => $e->getMessage(), 'message' => null];
        }

        if ($transactions === []) {
            return ['error' => __('requisitions.validation.no_stock_available'), 'message' => null];
        }

        $totalIssuedBase = array_reduce(
            $transactions,
            fn (string $sum, IssueTransaction $t) => bcadd($sum, $t->qty_issued_base, 6),
            '0.000000',
        );
        $shortfallBase = bcsub($converter->toItemBase($item, $unit, $qty), $totalIssuedBase, 6);

        $message = bccomp($shortfallBase, '0', 6) > 0
            ? __('requisitions.issue_recorded_partial', [
                'containers' => count($transactions),
                'issued' => rtrim(rtrim($totalIssuedBase, '0'), '.'),
                'unit' => $item->baseUnit?->code,
            ])
            : __('requisitions.issue_recorded_multi', ['containers' => count($transactions)]);

        return ['error' => null, 'message' => $message];
    }

    /** FR-RQ-11: the receiver's own OTP request — sent to the requisition's requester (the usual receiver). */
    public function sendReceiverOtp(Requisition $requisition, ReceiverOtpService $otp): RedirectResponse
    {
        $this->authorize('issue', $requisition);

        $otp->send($requisition->requester()->firstOrFail(), $requisition);

        return back()->with('status', __('requisitions.otp_sent'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?string, 1: ?string} [signatureHash, signatureImagePath]
     */
    private function resolveReceiverSignature(
        array $data,
        User $receiver,
        Requisition $requisition,
        SignatureImageService $signatures,
        ReceiverOtpService $otp,
    ): array {
        if (! empty($data['signature_image'])) {
            $stored = $signatures->store($data['signature_image']);

            return [$stored['hash'], $stored['path']];
        }

        if ($otp->verify($receiver, $requisition, (string) $data['otp_code'])) {
            return [hash('sha256', "OTP|{$requisition->id}|{$receiver->id}|".now()->toISOString()), null];
        }

        return [null, null];
    }
}
