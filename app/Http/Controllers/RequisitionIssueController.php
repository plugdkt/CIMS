<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Services\FefoContainerSelector;
use App\Domain\Requisition\Exceptions\ExcessiveIssueQuantityException;
use App\Domain\Requisition\Exceptions\InvalidRequisitionTransitionException;
use App\Domain\Requisition\Exceptions\InvalidSignatureImageException;
use App\Domain\Requisition\Services\IssueService;
use App\Domain\Requisition\Services\ReceiverOtpService;
use App\Domain\Requisition\Services\SignatureImageService;
use App\Http\Requests\RequisitionIssueRequest;
use App\Models\Container;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/** FR-RQ-09/10: dispensing against an APPROVED/PARTIALLY_ISSUED requisition, one container at a time. */
final class RequisitionIssueController extends Controller
{
    public function create(Requisition $requisition, FefoContainerSelector $selector): View
    {
        $this->authorize('issue', $requisition);

        $requisition->load(['items.item', 'items.unit', 'requester']);

        $lines = $requisition->items->map(fn (RequisitionItem $line) => [
            'line' => $line,
            'remaining_base' => bcsub($line->qty_requested_base, $line->qty_issued_base, 6),
            'containers' => $selector->recommend($line->item()->firstOrFail()),
        ]);

        return view('requisitions.issue', [
            'requisition' => $requisition,
            'lines' => $lines,
            'units' => Unit::orderBy('sort_order')->get(),
            'selector' => $selector,
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
