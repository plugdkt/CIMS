<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Disposal;
use App\Models\User;

/** FR-ST-05: `disposal.request` (SCIENTIST) requests; `disposal.approve` (LAB_MANAGER) approves/rejects. */
final class DisposalPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'disposal.request') || $this->hasPermission($user, 'disposal.approve');
    }

    public function view(User $user, Disposal $disposal): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'disposal.request');
    }

    public function decide(User $user, Disposal $disposal): bool
    {
        if (! $this->hasPermission($user, 'disposal.approve') || $disposal->status !== 'PENDING') {
            return false;
        }

        if ($user->hasRole('LAB_MANAGER')) {
            $disposalLabId = $this->labIdFor($disposal);

            return $disposalLabId === null || $disposalLabId === $user->lab_id;
        }

        return true;
    }

    /**
     * `location_id`/`locations.lab_id` are both nullable, so this genuinely can be empty
     * — written as an explicit `if` (not `?->`/`??`) since PHPStan's nullsafe inference
     * for chained relation access is unreliable in either direction (see CLAUDE.md).
     */
    private function labIdFor(Disposal $disposal): ?int
    {
        $container = $disposal->container()->first();
        if ($container === null) {
            return null;
        }

        $location = $container->location()->first();
        if ($location === null) {
            return null;
        }

        $lab = $location->lab()->first();

        return $lab?->id;
    }
}
