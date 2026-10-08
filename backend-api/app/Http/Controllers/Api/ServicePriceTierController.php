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
            'Service price tiers retrieved successfully.'
        );
    }

    public function store(StoreServicePriceTierRequest $request, int $serviceId): JsonResponse
    {
        $tier = $this->findOwnedService($request, $serviceId)
            ->priceTiers()
            ->create($request->validated());

        return ApiResponse::success(null, 'Service price tier created successfully.', 201);
    }

    public function update(UpdateServicePriceTierRequest $request, int $id): JsonResponse
    {
        $tier = $this->findOwnedTier($request, $id);
        $tier->update($request->validated());

        return ApiResponse::success(null, 'Service price tier updated successfully.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->findOwnedTier($request, $id)->delete();

        return ApiResponse::success(null, 'Service price tier deleted successfully.');
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
