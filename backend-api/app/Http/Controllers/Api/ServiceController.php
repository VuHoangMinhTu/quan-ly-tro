<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Service\StoreServiceRequest;
use App\Http\Requests\Service\UpdateServiceRequest;
use App\Models\BoardingHouse;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function index(Request $request, int $boardingHouseId): JsonResponse
    {
        $services = $this->findOwnedBoardingHouse($request, $boardingHouseId)
            ->services()
            ->with('priceTiers')
            ->latest()
            ->get();

        return ApiResponse::success($services, 'Services retrieved successfully.');
    }

    public function store(StoreServiceRequest $request, int $boardingHouseId): JsonResponse
    {
        $service = $this->findOwnedBoardingHouse($request, $boardingHouseId)
            ->services()
            ->create($request->validated());

        return ApiResponse::success(null, 'Service created successfully.', 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success(
            $this->findOwnedService($request, $id)->load('priceTiers'),
            'Service retrieved successfully.'
        );
    }

    public function update(UpdateServiceRequest $request, int $id): JsonResponse
    {
        $service = $this->findOwnedService($request, $id);
        $service->update($request->validated());

        return ApiResponse::success(null, 'Service updated successfully.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->findOwnedService($request, $id)->delete();

        return ApiResponse::success(null, 'Service deleted successfully.');
    }
    // 

    private function findOwnedBoardingHouse(Request $request, int $id): BoardingHouse
    {
        return $request->user()->landlord->boardingHouses()->findOrFail($id);
    }

    private function findOwnedService(Request $request, int $id): Service
    {
        return Service::query()
            ->whereHas('boardingHouse', fn ($query) => $query->where('landlord_id', $request->user()->landlord->id))
            ->findOrFail($id);
    }
}
