<?php

namespace App\Http\Controllers\App;

use App\Domain\Reports\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Sales dashboard page + JSON (docs/GOLD_RECEIVED_AND_DASHBOARD.md §8). */
class DashboardController extends BaseController
{
    public function show(Request $request, DashboardService $dashboard)
    {
        $tenant = $this->tenant();
        $range = in_array($request->query('range'), $dashboard->access($tenant)['ranges'], true) ? $request->query('range') : 'month';
        if ($range === 'custom') {
            $range = 'month';
        }

        return view('app.dashboard', [
            'access' => $dashboard->access($tenant),
            'report' => $dashboard->report($tenant, $range),
        ]);
    }

    public function data(Request $request, DashboardService $dashboard): JsonResponse
    {
        $data = $request->validate([
            'range' => ['required', 'string', 'max:10'],
            'from' => ['nullable', 'string', 'max:12'],
            'to' => ['nullable', 'string', 'max:12'],
        ]);

        return response()->json($dashboard->report($this->tenant(), $data['range'], $data['from'] ?? null, $data['to'] ?? null))
            ->header('Cache-Control', 'no-store');
    }
}
