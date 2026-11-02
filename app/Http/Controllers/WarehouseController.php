<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Reporting\Services\DashboardService;
use App\Http\Requests\WarehouseOverviewRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;

/**
 * ITM-2610-052: the "ภาพรวมงานค้าง" tab of the warehouse card. The numbers/lists/charts that used
 * to fill the home page live here now, with the same scoping as before: a branch manager sees
 * their own branch, ADMIN every branch, and the analytics stay behind `report.view`.
 */
final class WarehouseController extends Controller
{
    public function overview(WarehouseOverviewRequest $request, DashboardService $dashboard): View
    {
        /** @var User $user */
        $user = $request->user();
        $labId = $user->isBranchManager() ? $user->lab_id : null;

        $data = [
            'approvalCount' => $dashboard->approvalQueueCount($user),
            'dispenseCount' => $dashboard->dispenseQueueCount($user),
        ];

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

        return view('warehouse.overview', $data);
    }
}
