<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * A STUDENT/STAFF account whose `users.lab_id` hasn't been assigned yet by an ADMIN
 * (see `UserRoleManager::setLab()`) has no branch to snapshot onto a requisition — same
 * "waiting for an admin action" shape as `PendingRoleController`.
 */
final class PendingLabController extends Controller
{
    public function __invoke(): View
    {
        return view('auth.pending-lab');
    }
}
