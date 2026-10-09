<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\RoomService\UpdateRoomServicesRequest;
use App\Models\BoardingHouse;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoomServiceController extends Controller
{
    public function index(Request $request, int $roomId): JsonResponse
    {
        $services = $this->findOwnedRoom($request, $roomId)
            ->services()
            ->wherePivot('is_active', true)
            ->with('priceTiers')
            ->orderBy('services.id')
            ->get();

        return ApiResponse::success($services, 'Room services retrieved successfully.');
    }

    public function update(UpdateRoomServicesRequest $request, int $roomId): JsonResponse
    {
        $serviceIds = array_values(array_unique(array_map('intval', $request->validated('service_ids'))));
        $boardingHouseId = $this->findOwnedRoom($request, $roomId)->boarding_house_id;

        DB::transaction(function () use ($request, $roomId, $serviceIds, $boardingHouseId): void {
            // Lock before any non-locking read: MySQL must not retain a snapshot taken before a competing update commits.
            BoardingHouse::query()->whereKey($boardingHouseId)->lockForUpdate()->firstOrFail();
            $room = $this->findOwnedRoom($request, $roomId, true);

            // Check mutable service eligibility again under locks before writing the assignment.
            $request->validateServices($room, $serviceIds, true);

            // Keep inactive assignments for history; changing services must not delete old meters or invoice items.
            $room->services()->newPivotQuery()
                ->where('is_active', true)
                ->whereNotIn('service_id', $serviceIds)
                ->update(['is_active' => false, 'updated_at' => now()]);

            $room->services()->syncWithoutDetaching(
                array_fill_keys($serviceIds, ['is_active' => true])
            );
        }, 3);

        return ApiResponse::success(null, 'Room services updated successfully.');
    }

    private function findOwnedRoom(Request $request, int $roomId, bool $lockForUpdate = false): Room
    {
        return Room::query()
            ->whereHas('boardingHouse', fn ($query) => $query->where('landlord_id', $request->user()->landlord->id))
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->findOrFail($roomId);
    }
}
