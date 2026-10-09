<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Room\StoreRoomRequest;
use App\Http\Requests\Room\UpdateRoomRequest;
use App\Models\BoardingHouse;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoomController extends Controller
{
    public function index(Request $request, int $boardingHouseId): JsonResponse
    {
        $boardingHouse = $this->findOwnedBoardingHouse($request, $boardingHouseId);
        $rooms = $boardingHouse->rooms()->with('amenities')->latest()->get();

        return ApiResponse::success($rooms, 'Rooms retrieved successfully.');
    }

    public function store(StoreRoomRequest $request, int $boardingHouseId): JsonResponse
    {
        $boardingHouse = $this->findOwnedBoardingHouse($request, $boardingHouseId);
        $data = $request->validated();
        $amenityIds = $data['amenity_ids'] ?? [];
        unset($data['amenity_ids']);

        $room = DB::transaction(function () use ($boardingHouse, $data, $amenityIds): Room {
            $room = $boardingHouse->rooms()->create($data);
            $room->amenities()->sync($amenityIds);

            return $room->load('amenities');
        });

        return ApiResponse::success($room, 'Room created successfully.', 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success(
            $this->findOwnedRoom($request, $id)->load('amenities'),
            'Room retrieved successfully.'
        );
    }

    public function update(
        UpdateRoomRequest $request,
        int $id
    ): JsonResponse {
        $room = $this->findOwnedRoom($request, $id);

        $data = $request->validated();

        $hasAmenities = array_key_exists(
            'amenity_ids',
            $data
        );

        $amenityIds = $data['amenity_ids'] ?? [];

        unset($data['amenity_ids']);

        DB::transaction(function () use (
            $room,
            $data,
            $hasAmenities,
            $amenityIds
        ) {
            $room->update($data);

            if ($hasAmenities) {
                $room->amenities()->sync($amenityIds);
            }
        });

        return ApiResponse::success(null, 'Room updated successfully.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->findOwnedRoom($request, $id)->delete();

        return ApiResponse::success(null, 'Room deleted successfully.');
    }

    private function findOwnedBoardingHouse(Request $request, int $boardingHouseId): BoardingHouse
    {
        return $request->user()->landlord->boardingHouses()->findOrFail($boardingHouseId);
    }

    private function findOwnedRoom(Request $request, int $id): Room
    {
        return Room::query()
            ->whereHas('boardingHouse', fn ($query) => $query->where(
                'landlord_id',
                $request->user()->landlord->id
            ))
            ->findOrFail($id);
    }
}
