<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\DashboardAnalytics;
use App\Services\DashboardPeriod;
use App\Services\DashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function summary(Request $request, DashboardAnalytics $dashboard): JsonResponse
    {
        $scope = DashboardPeriod::scope($request->query());
        $house = isset($scope['boarding_house_id']) ? (int) $scope['boarding_house_id'] : null;

        return ApiResponse::success($dashboard->snapshot($request->user(), $house, CarbonImmutable::now(DashboardPeriod::ZONE)))
            ->header('Cache-Control', 'private, no-store');
    }

    public function analytics(Request $request, DashboardAnalytics $dashboard): JsonResponse
    {
        return ApiResponse::success($dashboard->analytics($request->user(), $request->query(), CarbonImmutable::now(DashboardPeriod::ZONE)))
            ->header('Cache-Control', 'private, no-store');
    }

    public function __invoke(Request $request, DashboardService $dashboard): JsonResponse
    {
        $data = $request->query('include_revenue') === '0'
            ? $dashboard->summaryWithoutRevenue($request->user())
            : $dashboard->summary($request->user());

        return ApiResponse::success($data, 'Lấy dữ liệu tổng quan thành công.');
    }
}
