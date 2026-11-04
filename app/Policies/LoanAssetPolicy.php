<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LoanAsset;
use App\Models\User;

/**
 * ITM-2610-051 step 1: who may see and manage the register of lending assets.
 *
 * IMPORTANT: no method here may be named like a permission code (`loan.asset_manage`, `loan.verify`, ...).
 * AppServiceProvider's Gate::before returns `true` straight away when the ability name equals a permission
 * code the user holds, which would skip the branch checks below entirely. The methods therefore use
 * different names (registerAsset, adjustAsset, ...) and call hasPermission() themselves.
 *
 * Branch scoping: a branch manager (LAB_MANAGER/AUDITOR) only reaches assets whose `lab_id` equals their own
 * `users.lab_id` (no branch = nothing). ADMIN reaches every branch, decided by the ADMIN ROLE and not by
 * whatever `lab_id` the account happens to hold (an ADMIN with a branch in the profile is not narrowed).
 * The price (replacement value) is visible only to those who manage the register.
 */
final class LoanAssetPolicy extends Policy
{
    /** The register page: managers of lending assets (own branch) and ADMIN (all). */
    public function browseRegistry(User $user): bool
    {
        return $this->hasPermission($user, 'loan.asset_manage')
            && ($user->hasRole('ADMIN') || $user->lab_id !== null);
    }

    /** One asset's page (movement log, price). */
    public function viewRegistryEntry(User $user, LoanAsset $asset): bool
    {
        // approvals.md K: a holder of loan.asset_approve (a scientist named as the second person of an
        // ADJUST / RETIRE) must be able to open that asset to confirm what they were named for — read only,
        // own branch only (never by ADMIN role, which does not hold loan.asset_approve; `sameBranchOrAdmin`
        // still lets an ADMIN with loan.asset_manage through, as before). Editing stays with asset_manage.
        return ($this->hasPermission($user, 'loan.asset_manage') && $this->sameBranchOrAdmin($user, (int) $asset->lab_id))
            || ($this->hasPermission($user, 'loan.asset_approve') && $user->lab_id !== null && (int) $user->lab_id === (int) $asset->lab_id);
    }

    public function registerAsset(User $user): bool
    {
        return $this->browseRegistry($user);
    }

    public function editAsset(User $user, LoanAsset $asset): bool
    {
        // Needs the register-management right itself: viewRegistryEntry() alone now also admits read-only approvers.
        return $this->hasPermission($user, 'loan.asset_manage')
            && $this->sameBranchOrAdmin($user, (int) $asset->lab_id)
            && $asset->status === 'ACTIVE';
    }

    public function adjustAsset(User $user, LoanAsset $asset): bool
    {
        return $this->editAsset($user, $asset);
    }

    public function retireAsset(User $user, LoanAsset $asset): bool
    {
        return $this->editAsset($user, $asset);
    }

    /** The "available to lend" page: counter staff, managers and ADMIN. No prices, own branch only (ADMIN: any). */
    public function browseAvailable(User $user): bool
    {
        return ($this->hasPermission($user, 'loan.view_queue') || $this->hasPermission($user, 'loan.asset_manage'))
            && ($user->hasRole('ADMIN') || $user->lab_id !== null);
    }

    public function seeReplacementValue(User $user): bool
    {
        return $this->hasPermission($user, 'loan.asset_manage');
    }

    /** Running the hash-chain check from a page. ADMIN only in practice (loan.verify). */
    public function verifyChain(User $user): bool
    {
        return $this->hasPermission($user, 'loan.verify');
    }

    private function sameBranchOrAdmin(User $user, int $labId): bool
    {
        return $user->hasRole('ADMIN') || ($user->lab_id !== null && (int) $user->lab_id === $labId);
    }
}
