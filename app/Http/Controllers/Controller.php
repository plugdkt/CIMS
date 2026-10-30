<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * requisitions.requester_status is NOT NULL and comes from users.person_type. The
     * complete-profile page always sets it, but a profile flagged complete without one
     * (legacy/manual data) must go back to that page rather than hit a DB error.
     */
    protected function personTypeGate(User $user): ?RedirectResponse
    {
        if ($user->person_type === null) {
            return redirect()->route('account.complete-profile')->with('status', __('requisitions.person_type_missing'));
        }

        return null;
    }
}
