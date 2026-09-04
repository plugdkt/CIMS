<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Requisition;
use App\Models\User;

/**
 * FR-RQ-01..05: `requisition.create` (STUDENT/STAFF per PermissionSeeder) owns creating
 * and editing one's own DRAFT requisitions. `requisition.view_own` sees only requisitions
 * the user requested or advises; `requisition.view_all` (SCIENTIST/LAB_MANAGER/AUDITOR)
 * sees every requisition.
 */
final class RequisitionPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'requisition.view_own')
            || $this->hasPermission($user, 'requisition.view_all');
    }

    public function view(User $user, Requisition $requisition): bool
    {
        if ($this->hasPermission($user, 'requisition.view_all')) {
            return true;
        }

        return $this->hasPermission($user, 'requisition.view_own')
            && ($requisition->requester_id === $user->id || $requisition->advisor_id === $user->id);
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'requisition.create');
    }

    public function update(User $user, Requisition $requisition): bool
    {
        return $this->hasPermission($user, 'requisition.create')
            && $requisition->requester_id === $user->id
            && $requisition->status === 'DRAFT';
    }

    /** BR-01: DRAFT | SUBMITTED can be cancelled by the requester who owns it. */
    public function cancel(User $user, Requisition $requisition): bool
    {
        return $this->hasPermission($user, 'requisition.create')
            && $requisition->requester_id === $user->id
            && in_array($requisition->status, ['DRAFT', 'SUBMITTED'], true);
    }

    /** FR-RQ-07 "ผ่านระบบ": the requisition's own advisor, logged in, deciding a SUBMITTED requisition. */
    public function advisorDecide(User $user, Requisition $requisition): bool
    {
        return $this->hasPermission($user, 'requisition.approve_advisor')
            && $requisition->advisor_id === $user->id
            && $requisition->status === 'SUBMITTED';
    }

    /**
     * FR-RQ-08: the scientist reviews a requisition once it's past the advisor step (or
     * never needed one). Whether it's actually *decidable yet* for a STUDENT requisition
     * still SUBMITTED with no advisor sign-off is BR-02's job, enforced inside
     * `ApprovalService::scientistDecide()` itself — this ability only gates who may open
     * the review action at all.
     */
    public function scientistDecide(User $user, Requisition $requisition): bool
    {
        return $this->hasPermission($user, 'requisition.approve_scientist')
            && in_array($requisition->status, ['SUBMITTED', 'ADVISOR_APPROVED'], true);
    }

    /** FR-RQ-09/10: dispensing only makes sense once a requisition has been approved. */
    public function issue(User $user, Requisition $requisition): bool
    {
        return $this->hasPermission($user, 'requisition.issue')
            && in_array($requisition->status, ['APPROVED', 'PARTIALLY_ISSUED'], true);
    }
}
