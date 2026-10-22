<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\RoomTenant\StoreRoomTenantRequest;
use App\Http\Requests\RoomTenant\UpdateRoomTenantRequest;
use App\Models\Room;
use App\Models\RoomTenant;
use App\Models\Tenant;
use App\Models\Bed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoomTenantController extends Controller
{
    public function index(Request $request, int $roomId): JsonResponse
    {
        $roomTenants = $this->findOwnedRoom($request, $roomId)
            ->roomTenants()
            ->with(['tenant', 'bed'])
            ->orderBy('status')
            ->orderByDesc('move_in_date')
            ->get();

        return ApiResponse::success($roomTenants, 'Lấy danh sách người ở trong phòng thành công.');
    }

    public function store(StoreRoomTenantRequest $request, int $roomId): JsonResponse
    {
        $room = $this->findOwnedRoom($request, $roomId);
        $tenant = $this->findOwnedTenant($request, $request->integer('tenant_id'));
        $data = $request->validated();
        $data['tenant_id'] = $tenant->id;

        DB::transaction(function () use ($room, $data): void {
            if ($room->room_type === 'DORMITORY' && ($data['status'] ?? null) === 'ACTIVE') {
                $this->reserveBed($room, (int) $data['bed_id']);
            }
            $room->roomTenants()->create($data);
        });

        return ApiResponse::success(null, 'Thêm người ở trong phòng thành công.', 201);
    }

    public function update(UpdateRoomTenantRequest $request, int $id): JsonResponse
    {
        $roomTenant = $this->findOwnedRoomTenant($request, $id);
        $tenant = $this->findOwnedTenant($request, $request->integer('tenant_id'));
        $data = $request->validated();
        $data['tenant_id'] = $tenant->id;

        if ($roomTenant->contract_id !== null) {
            if ((int) $data['tenant_id'] !== (int) $roomTenant->tenant_id
                || $data['move_in_date'] !== $roomTenant->move_in_date->format('Y-m-d')
                || (isset($data['is_primary']) && (bool) $data['is_primary'] !== $roomTenant->is_primary)
                || $roomTenant->status === 'MOVED_OUT') {
                throw ValidationException::withMessages(['status' => 'Không được ghi đè lịch sử người ở gắn với hợp đồng. Chỉ ghi nhận chuyển đi.']);
            }
        }
        DB::transaction(function () use ($roomTenant, $data): void {
            $previousBedId = $roomTenant->bed_id;
            $wasActive = $roomTenant->status === 'ACTIVE';
            $nextBedId = $data['bed_id'] ?? null;
            $willBeActive = $data['status'] === 'ACTIVE';
            if ($roomTenant->room->room_type === 'DORMITORY' && $willBeActive && (! $wasActive || (int) $previousBedId !== (int) $nextBedId)) {
                $this->reserveBed($roomTenant->room, (int) $nextBedId);
            }
            $roomTenant->update($data);
            if ($previousBedId && $wasActive && (! $willBeActive || (int) $previousBedId !== (int) $nextBedId)) {
                Bed::query()->whereKey($previousBedId)->where('status', 'OCCUPIED')->update(['status' => 'AVAILABLE']);
            }
        });

        return ApiResponse::success(null, 'Cập nhật người ở trong phòng thành công.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $membership = $this->findOwnedRoomTenant($request, $id);
        if ($membership->contract_id !== null) {
            throw ValidationException::withMessages(['status' => 'Người ở gắn với hợp đồng phải được ghi nhận chuyển đi, không xóa lịch sử.']);
        }
        DB::transaction(function () use ($membership): void {
            $bedId = $membership->bed_id;
            $wasActive = $membership->status === 'ACTIVE';
            $membership->delete();
            if ($bedId && $wasActive) Bed::query()->whereKey($bedId)->where('status', 'OCCUPIED')->update(['status' => 'AVAILABLE']);
        });

        return ApiResponse::success(null, 'Xóa người ở trong phòng thành công.');
    }

    private function findOwnedRoom(Request $request, int $id): Room
    {
        return Room::query()
            ->whereHas('boardingHouse', fn ($query) => $query->where('landlord_id', $request->user()->landlord->id))
            ->findOrFail($id);
    }

    private function reserveBed(Room $room, int $bedId): void
    {
        $bed = $room->beds()->whereKey($bedId)->lockForUpdate()->first();
        if (! $bed || $bed->status !== 'AVAILABLE' || $bed->roomTenants()->where('status', 'ACTIVE')->exists()) {
            throw ValidationException::withMessages(['bed_id' => 'Giường không còn trống. Vui lòng chọn giường khác.']);
        }
        $bed->update(['status' => 'OCCUPIED']);
    }

    private function findOwnedTenant(Request $request, int $id): Tenant
    {
        return Tenant::query()
            ->where('landlord_id', $request->user()->landlord->id)
            ->findOrFail($id);
    }

    private function findOwnedRoomTenant(Request $request, int $id): RoomTenant
    {
        return RoomTenant::query()
            ->whereHas('room.boardingHouse', fn ($query) => $query->where('landlord_id', $request->user()->landlord->id))
            ->findOrFail($id);
    }
}
