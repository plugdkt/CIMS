<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Services\FefoContainerSelector;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\Requisition\Exceptions\ExcessiveIssueQuantityException;
use App\Domain\Requisition\Exceptions\InvalidRequisitionTransitionException;
use App\Domain\Requisition\Exceptions\InvalidSignatureImageException;
use App\Domain\Requisition\Services\IssueService;
use App\Domain\Requisition\Services\ReceiverOtpService;
use App\Domain\Requisition\Services\ReturnService;
use App\Domain\Requisition\Services\SignatureImageService;
use App\Domain\Shared\UnitConverter;
use App\Http\Requests\RequisitionAutoIssueRequest;
use App\Http\Requests\RequisitionIssueRequest;
use App\Models\Container;
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
    ): View {
        /** @var User|null $user */
        $user = auth()->user();
        abort_unless($user !== null && ($user->can('issue', $requisition) || $user->can('return', $requisition)), 403);

        $requisition->load(['items.item', 'items.unit', 'requester']);

        // User-requested 2026-09-21: warn right where the actual dispensing happens
        // (not on the earlier review page) once an item's current balance is below
        // its reorder point — the person issuing is exactly who'd go restock it.
        $lines = $requisition->items->map(function (RequisitionItem $line) use ($selector, $returns, $stockBalance) {
            $item = $line->item()->firstOrFail();
            $balance = $stockBalance->currentBalance($item);

            return [
                'line' => $line,
                // User-requested 2026-09-23: remaining is against what was actually
                // approved, not what was requested, once a warehouse manager approves less.
                'remaining_base' => bcsub($line->approvedCeilingBase(), $line->qty_issued_base, 6),
                'returnable_base' => bcsub($line->qty_issued_base, $line->qty_returned_base, 6),
                'containers' => $selector->recommend($item),
                'issued_containers' => $returns->issuedContainersFor($line),
                'stock_balance' => $balance,
                'low_stock' => $stockBalance->isBelowReorderPoint($item, $balance),
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
    ): RedirectResponse {
        $data = $request->validated();
        $unit = Unit::whereKey($data['unit_id'])->firstOrFail();
        $item = $requisitionItem->item()->firstOrFail();
        $containers = $selector->recommendExcludingExpired($item);

        /** @var User $issuer */
        $issuer = $request->user();
        $receiver = $requisition->requester()->firstOrFail();

        /** @var numeric-string $qtyRequested */
        $qtyRequested = (string) $data['qty_issued'];

        try {
            [$signatureHash, $signatureImagePath] = $this->resolveReceiverSignature($data, $receiver, $requisition, $signatures, $otp);
        } catch (InvalidSignatureImageException $e) {
            return back()->withErrors(['signature_image' => $e->getMessage()]);
        }

        if ($signatureHash === null) {
            return back()->withErrors(['otp_code' => __('requisitions.validation.otp_code_invalid')]);
        }

        try {
            $transactions = $service->issueAcrossContainers(
                $requisitionItem,
                $containers,
                $qtyRequested,
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

        if ($transactions === []) {
            return back()->withErrors(['issue' => __('requisitions.validation.no_stock_available')]);
        }

        $totalIssuedBase = array_reduce(
            $transactions,
            fn (string $sum, IssueTransaction $t) => bcadd($sum, $t->qty_issued_base, 6),
            '0.000000',
        );
        $shortfallBase = bcsub($converter->toItemBase($item, $unit, $qtyRequested), $totalIssuedBase, 6);

        $status = bccomp($shortfallBase, '0', 6) > 0
            ? __('requisitions.issue_recorded_partial', [
                'containers' => count($transactions),
                'issued' => rtrim(rtrim($totalIssuedBase, '0'), '.'),
                'unit' => $item->baseUnit?->code,
            ])
            : __('requisitions.issue_recorded_multi', ['containers' => count($transactions)]);

        return redirect()->route('requisitions.issue.create', $requisition)->with('status', $status);
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
