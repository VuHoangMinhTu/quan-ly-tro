<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\ServicePriceTier\StoreServicePriceTierRequest;
use App\Http\Requests\ServicePriceTier\UpdateServicePriceTierRequest;
use App\Models\Service;
use App\Models\ServicePriceTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServicePriceTierController extends Controller
{
    public function index(Request $request, int $serviceId): JsonResponse
    {
        return ApiResponse::success(
            $this->findOwnedService($request, $serviceId)->priceTiers()->get(),
            'Lấy danh sách bậc giá thành công.'
        );
    }

    public function store(StoreServicePriceTierRequest $request, int $serviceId): JsonResponse
    {
        $tier = $this->findOwnedService($request, $serviceId)
            ->priceTiers()
            ->create($request->validated());

        return ApiResponse::success(null, 'Tạo bậc giá thành công.', 201);
    }

    public function update(UpdateServicePriceTierRequest $request, int $id): JsonResponse
    {
        $tier = $this->findOwnedTier($request, $id);
        $tier->update($request->validated());

        return ApiResponse::success(null, 'Cập nhật bậc giá thành công.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->findOwnedTier($request, $id)->delete();

        return ApiResponse::success(null, 'Xóa bậc giá thành công.');
    }

    private function findOwnedService(Request $request, int $id): Service
    {
        return Service::query()
            ->whereHas('boardingHouse', fn ($query) => $query->where('landlord_id', $request->user()->landlord->id))
            ->findOrFail($id);
    }

    private function findOwnedTier(Request $request, int $id): ServicePriceTier
    {
        return ServicePriceTier::query()
            ->whereHas('service.boardingHouse', fn ($query) => $query->where('landlord_id', $request->user()->landlord->id))
            ->findOrFail($id);
    }
}
