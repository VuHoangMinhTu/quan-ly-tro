<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Residence\SaveResidenceProfileRequest;
use App\Services\BoardingHouseAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ResidenceProfileController extends Controller
{
    public function __construct(private BoardingHouseAccessService $access) {}

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->access->house($request->user(), $id)->residenceProfile, 'Lấy thông tin chủ hộ thành công.');
    }

    public function update(SaveResidenceProfileRequest $request, int $id): JsonResponse
    {
        DB::transaction(function () use ($request, $id): void {
            $this->access->house($request->user(), $id, true)->residenceProfile()->updateOrCreate([], $request->validated());
        });

        return ApiResponse::success(null, 'Cập nhật thông tin chủ hộ thành công.');
    }
}
