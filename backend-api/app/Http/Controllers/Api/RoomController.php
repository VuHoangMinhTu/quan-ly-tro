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
        $rooms = $boardingHouse->rooms()->with(['amenities', 'floor'])->withCount('beds')->withActiveOccupancy()
            ->withExists(['contracts as has_contracts', 'roomTenants as has_occupants', 'invoices as has_invoices',
                'utilityMeters as has_meters', 'services as has_services', 'residenceDeclarations as has_residence'])
            ->latest()->get();
        $rooms->each(function (Room $room): void {
            $room->setAttribute('has_history', $room->has_contracts || $room->has_occupants || $room->has_invoices
                || $room->has_meters || $room->has_services || $room->has_residence);
            unset($room->has_contracts, $room->has_occupants, $room->has_invoices, $room->has_meters, $room->has_services, $room->has_residence);
        });

        return ApiResponse::success($rooms, 'Lấy danh sách phòng thành công.');
    }

    public function store(StoreRoomRequest $request, int $boardingHouseId): JsonResponse
    {
        $boardingHouse = $this->findOwnedBoardingHouse($request, $boardingHouseId);
        $data = $request->validated();
        $amenityIds = $data['amenity_ids'] ?? [];
        $beds = $data['beds'] ?? [];
        unset($data['amenity_ids'], $data['beds']);

        if (($data['room_type'] ?? 'PRIVATE_ROOM') !== 'DORMITORY' && $beds !== []) {
            throw ValidationException::withMessages(['beds' => 'Chỉ phòng KTX mới có giường.']);
        }

        $room = DB::transaction(function () use ($boardingHouse, $data, $amenityIds, $beds): Room {
            $room = $boardingHouse->rooms()->create($data);
            $room->amenities()->sync($amenityIds);
            foreach ($beds as $bed) {
                $room->beds()->create([
                    'bed_code' => trim($bed['bed_code']),
                    'monthly_rent' => $bed['monthly_rent'] ?? null,
                    'status' => $bed['status'] ?? 'AVAILABLE',
                    'note' => $bed['note'] ?? null,
                ]);
            }

            return $room->load(['amenities', 'floor', 'beds']);
        });

        return ApiResponse::success($room, 'Tạo phòng thành công.', 201);
    }

    public function storeBulk(StoreBulkRoomsRequest $request, int $boardingHouseId): JsonResponse
    {
        $boardingHouse = $this->findOwnedBoardingHouse($request, $boardingHouseId);
        $validated = $request->validated();
        $rooms = $validated['rooms'];
        $floorId = $validated['floor_id'] ?? null;
        if ($floorId !== null && ! $boardingHouse->floors()->whereKey($floorId)->exists()) {
            throw ValidationException::withMessages(['floor_id' => 'Tầng không thuộc nhà trọ này.']);
        }

        DB::transaction(function () use ($boardingHouse, $rooms, $floorId): void {
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
                    'room_name' => $room['room_name'] ?? null,
                    'description' => $room['description'] ?? null,
                    'floor_id' => $floorId,
                    'room_type' => $room['room_type'] ?? 'PRIVATE_ROOM',
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
        $room = $this->findOwnedRoom($request, $id)->load(['amenities', 'floor'])->loadCount(['beds', 'roomTenants as active_occupants_count' => fn ($occupants) => $occupants->where('status', 'ACTIVE')->whereHas('tenant')]);
        $room->setAttribute('has_history', $room->hasHistory());

        return ApiResponse::success(
            $room,
            'Lấy thông tin phòng thành công.'
        );
    }

    public function update(
        UpdateRoomRequest $request,
        int $id
    ): JsonResponse {
        $room = $this->findOwnedRoom($request, $id);

        $data = $request->validated();
        if ($room->status === 'INACTIVE' && ($data['status'] ?? 'INACTIVE') !== 'INACTIVE') {
            throw ValidationException::withMessages(['status' => 'Phòng đã ngừng sử dụng không thể chuyển lại trạng thái hoạt động.']);
        }
        if (array_key_exists('floor_id', $data) && $data['floor_id'] !== null && ! $room->boardingHouse->floors()->whereKey($data['floor_id'])->exists()) {
            throw ValidationException::withMessages(['floor_id' => 'Tầng không thuộc nhà trọ này.']);
        }

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
        $archived = DB::transaction(function () use ($request, $id): bool {
            $room = $this->findOwnedRoom($request, $id);
            $room = Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();
            if ($room->hasHistory()) {
                $room->update(['status' => 'INACTIVE']);

                return true;
            }

            // SoftDeletes remains for legacy rows; a never-used room is actually removed.
            $room->forceDelete();

            return false;
        });

        return ApiResponse::success(['archived' => $archived], $archived ? 'Đã ngừng sử dụng phòng.' : 'Xóa phòng thành công.');
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
