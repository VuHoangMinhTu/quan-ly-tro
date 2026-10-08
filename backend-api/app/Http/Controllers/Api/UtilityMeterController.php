<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\UtilityMeter\StoreUtilityMeterRequest;
use App\Http\Requests\UtilityMeter\UpdateUtilityMeterRequest;
use App\Models\Room;
use App\Models\Service;
use App\Models\UtilityMeter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UtilityMeterController extends Controller
{
    public function index(Request $request, int $roomId): JsonResponse
    {
        $meters = $this->room($request, $roomId)->utilityMeters()->with(['service', 'latestReading'])->get();

        return ApiResponse::success($meters, 'Utility meters retrieved successfully.');
    }

    public function store(StoreUtilityMeterRequest $request, int $roomId): JsonResponse
    {
        $room = $this->room($request, $roomId);
        $data = $request->validated();
        $this->validateMeter($room, $data);
        $room->utilityMeters()->create($data);

        return ApiResponse::success(null, 'Utility meter created successfully.', 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->meter($request, $id)->load(['room', 'service', 'latestReading']), 'Utility meter retrieved successfully.');
    }

    public function update(UpdateUtilityMeterRequest $request, int $id): JsonResponse
    {
        $meter = $this->meter($request, $id);
        $data = $request->validated();
        $this->validateMeter($meter->room, $data, $meter->id);
        $meter->update($data);

        return ApiResponse::success(null, 'Utility meter updated successfully.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->meter($request, $id)->delete();

        return ApiResponse::success(null, 'Utility meter deleted successfully.');
    }

    private function room(Request $request, int $id): Room
    {
        return Room::whereHas('boardingHouse', fn ($query) => $query->where('landlord_id', $request->user()->landlord->id))->findOrFail($id);
    }

    private function meter(Request $request, int $id): UtilityMeter
    {
        return UtilityMeter::whereHas('room.boardingHouse', fn ($query) => $query->where('landlord_id', $request->user()->landlord->id))->findOrFail($id);
    }

    private function validateMeter(Room $room, array $data, ?int $ignoreId = null): void
    {
        $service = Service::findOrFail($data['service_id']);
        if ($service->boarding_house_id !== $room->boarding_house_id) {
            throw ValidationException::withMessages(['service_id' => 'The service must belong to the room boarding house.']);
        }
        if (! in_array($service->billing_method, ['PER_UNIT', 'TIERED'], true)) {
            throw ValidationException::withMessages(['service_id' => 'The service billing method must be PER_UNIT or TIERED.']);
        }
        if (($data['is_active'] ?? true) && UtilityMeter::where('room_id', $room->id)->where('service_id', $service->id)->where('is_active', true)->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))->exists()) {
            throw ValidationException::withMessages(['service_id' => 'The room already has an active meter for this service.']);
        }
    }
}
