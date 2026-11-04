<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Models\Requisition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * approvals.md K (CEO, 2026-11-04): requesting, approving and issuing one's own requisition is allowed, so the control
 * is a report. Lists the requisitions where the requester is also the approver (`scientist_id`) and/or the person who
 * issued it (an `issue_transactions.issuer_id`), or where the approver issued it. Computed from the existing tables
 * (no flag column, no migration); the matching audit rows (REQ_SELF_APPROVE / REQ_SELF_ISSUE) are written by
 * ApprovalService / IssueService at the time. Query Builder only.
 */
final class SelfApprovalReport
{
    /**
     * @param  list<string>|null  $itemTypes  warehouse managers: only requisitions of these kinds (empty = nothing); null = every kind
     * @return Collection<int, array{requisition: Requisition, approved_by_requester: bool, issued_by_requester: bool, issued_by_approver: bool}>
     */
    public function rows(?int $labId, ?string $from = null, ?string $to = null, ?array $itemTypes = null): Collection
    {
        // Issue lines where the issuer is the requester or the approver, grouped per requisition.
        $issueRows = DB::table('issue_transactions as it')
            ->join('requisition_items as ri', 'ri.id', '=', 'it.requisition_item_id')
            ->join('requisitions as r', 'r.id', '=', 'ri.requisition_id')
            ->when($labId !== null, fn ($q) => $q->where('r.lab_id', $labId))
            ->where(fn ($q) => $q->whereColumn('it.issuer_id', 'r.requester_id')->orWhereColumn('it.issuer_id', 'r.scientist_id'))
            ->select('r.id as requisition_id', 'it.issuer_id', 'r.requester_id', 'r.scientist_id')
            ->get();

        /** @var array<int, array{requester: bool, approver: bool}> $issueFlags */
        $issueFlags = [];
        foreach ($issueRows as $row) {
            $id = (int) $row->requisition_id;
            $issueFlags[$id] ??= ['requester' => false, 'approver' => false];
            if ((int) $row->issuer_id === (int) $row->requester_id) {
                $issueFlags[$id]['requester'] = true;
            }
            if ($row->scientist_id !== null && (int) $row->issuer_id === (int) $row->scientist_id) {
                $issueFlags[$id]['approver'] = true;
            }
        }

        $requisitions = Requisition::query()
            ->with(['requester:id,full_name', 'scientist:id,full_name', 'lab:id,name_th'])
            ->where('status', '!=', 'DRAFT')
            ->when($labId !== null, fn ($q) => $q->where('lab_id', $labId))
            ->when($itemTypes !== null, fn ($q) => $q->ofAnyItemType($itemTypes ?? []))
            ->when($from !== null && $from !== '', fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to !== null && $to !== '', fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->where(fn ($q) => $q->whereColumn('scientist_id', 'requester_id')->orWhereIn('id', array_keys($issueFlags)))
            ->orderByDesc('id')
            ->get();

        return $requisitions->map(fn (Requisition $requisition) => [
            'requisition' => $requisition,
            'approved_by_requester' => $requisition->scientist_id !== null && $requisition->scientist_id === $requisition->requester_id,
            'issued_by_requester' => $issueFlags[$requisition->id]['requester'] ?? false,
            'issued_by_approver' => $issueFlags[$requisition->id]['approver'] ?? false,
        ])->values();
    }
}
