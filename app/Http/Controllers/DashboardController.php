<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Reporting\Services\DashboardService;
use Illuminate\Contracts\View\View;

/**
 * §7.9: the pending-requisition count is role-scoped for every logged-in user; the
 * reorder/expiry/top-items/monthly-chart cards only render for `report.view` holders
 * (gated in the Blade view itself, same as every other report-adjacent nav link).
 */
final class DashboardController extends Controller
{
    public function index(DashboardService $dashboard): View
    {
        $user = auth()->user();
        if ($user === null) {
            return view('welcome');
        }

        $labId = $user->isBranchManager() ? $user->lab_id : null;

        $data = ['pendingRequisitions' => $dashboard->pendingRequisitionsCount($user)];

        if ($user->can('report.view')) {
            $belowReorderItems = $dashboard->belowReorderPointItems($labId);
            $expiringContainers = $dashboard->expiringWithin30DaysContainers($labId);

            $data['belowReorderCount'] = $belowReorderItems->count();
            $data['belowReorderItems'] = $belowReorderItems->take(5);
            $data['expiringCount'] = $expiringContainers->count();
            $data['expiringContainers'] = $expiringContainers->take(5);
            $data['topItems'] = $dashboard->topIssuedItems(10, $labId);
            $data['monthlySeries'] = $dashboard->monthlyIssuanceSeries($labId);
        }

        return view('home', $data);
    }
}
