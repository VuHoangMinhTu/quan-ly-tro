<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Room\StoreBulkRoomsRequest;
use App\Http\Requests\Room\StoreRoomRequest;
use App\Http\Requests\Room\UpdateRoomRequest;
use App\Models\BoardingHouse;
use App\Models\Room;
use App\Services\RoomOrderingService;
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
            ->get();
        $rooms = app(RoomOrderingService::class)->ordered($rooms);
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
            $room = app(RoomOrderingService::class)->create($boardingHouse, $data);
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
                app(RoomOrderingService::class)->create($boardingHouse, [
                    'room_code' => $room['room_code'],
                    'room_name' => $room['room_name'] ?? null,
                    'description' => $room['description'] ?? null,
                    'floor_id' => $floorId,
                    'room_type' => $room['room_type'] ?? 'PRIVATE_ROOM',
                    'private_room_type' => $room['private_room_type'] ?? null,
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

    public function reorder(Request $request, int $boardingHouseId, RoomOrderingService $ordering): JsonResponse
    {
        $house = $this->findOwnedBoardingHouse($request, $boardingHouseId);
        $data = $request->validate([
            'floor_id' => ['nullable', 'integer'],
            'room_ids' => ['required', 'array', 'list', 'min:1', 'max:5000'],
            'room_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $ordering->reorder($house, $data['floor_id'] ?? null, $data['room_ids']);

        return ApiResponse::success(null, 'Đã lưu thứ tự phòng.');
    }

    public function update(
        UpdateRoomRequest $request,
        int $id
    ): JsonResponse {
        $room = $this->findOwnedRoom($request, $id);

        $data = $request->validated();
        if (($data['room_type'] ?? $room->room_type) === 'PRIVATE_ROOM' && $room->room_type === 'DORMITORY' && $room->beds()->exists()) {
            throw ValidationException::withMessages(['room_type' => 'Không thể đổi phòng KTX có giường thành phòng trọ.']);
        }
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
            $ordering = app(RoomOrderingService::class);
            $next = $ordering->nextFor($room->boardingHouse, array_key_exists('floor_id', $data) ? $data['floor_id'] : $room->floor_id);
            if (array_key_exists('floor_id', $data) && (string) $data['floor_id'] !== (string) $room->floor_id && ! isset($data['sort_order'])) {
                $data['sort_order'] = $next;
            }
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
