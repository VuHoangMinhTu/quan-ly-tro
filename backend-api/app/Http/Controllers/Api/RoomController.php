<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Room\StoreBulkRoomsRequest;
use App\Http\Requests\Room\StoreRoomRequest;
use App\Http\Requests\Room\UpdateRoomRequest;
use App\Models\BoardingHouse;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RoomController extends Controller
{
    public function index(Request $request, int $boardingHouseId): JsonResponse
    {
        $boardingHouse = $this->findOwnedBoardingHouse($request, $boardingHouseId);
        $rooms = $boardingHouse->rooms()->with('amenities')->withActiveOccupancy()->latest()->get();

        return ApiResponse::success($rooms, 'Lấy danh sách phòng thành công.');
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

        return ApiResponse::success($room, 'Tạo phòng thành công.', 201);
    }

    public function storeBulk(StoreBulkRoomsRequest $request, int $boardingHouseId): JsonResponse
    {
        $boardingHouse = $this->findOwnedBoardingHouse($request, $boardingHouseId);
        $rooms = $request->validated()['rooms'];

        DB::transaction(function () use ($boardingHouse, $rooms): void {
            // Serialize batch additions to this house; no room is written if any code already exists.
            $boardingHouse->newQuery()->whereKey($boardingHouse->id)->lockForUpdate()->firstOrFail();
            $existingCodes = $boardingHouse->rooms()->pluck('room_code')
                ->mapWithKeys(fn (string $code) => [Str::lower($code) => true]);

            foreach ($rooms as $index => $room) {
                if ($existingCodes->has(Str::lower($room['room_code']))) {
                    throw ValidationException::withMessages([
                        "rooms.{$index}.room_code" => 'Mã phòng đã tồn tại trong nhà trọ này.',
                    ]);
                }
            }

            foreach ($rooms as $room) {
                $boardingHouse->rooms()->create([
                    'room_code' => $room['room_code'],
                    'area' => $room['area'] ?? null,
                    'monthly_rent' => $room['monthly_rent'] ?? null,
                    'max_tenants' => $room['max_tenants'] ?? null,
                    'status' => 'AVAILABLE',
                ]);
            }
        });

        return ApiResponse::success(null, 'Đã thêm '.count($rooms).' phòng thành công.', 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success(
            $this->findOwnedRoom($request, $id)->load('amenities')->loadCount(['roomTenants as active_occupants_count' => fn ($occupants) => $occupants->where('status', 'ACTIVE')->whereHas('tenant')]),
            'Lấy thông tin phòng thành công.'
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

        return ApiResponse::success(null, 'Cập nhật phòng thành công.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->findOwnedRoom($request, $id)->delete();

        return ApiResponse::success(null, 'Xóa phòng thành công.');
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
