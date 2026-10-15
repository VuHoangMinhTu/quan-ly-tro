<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\RoomTenant\StoreRoomTenantRequest;
use App\Http\Requests\RoomTenant\UpdateRoomTenantRequest;
use App\Models\Room;
use App\Models\RoomTenant;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RoomTenantController extends Controller
{
    public function index(Request $request, int $roomId): JsonResponse
    {
        $roomTenants = $this->findOwnedRoom($request, $roomId)
            ->roomTenants()
            ->with('tenant')
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

        $room->roomTenants()->create($data);

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
        $roomTenant->update($data);

        return ApiResponse::success(null, 'Cập nhật người ở trong phòng thành công.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $membership = $this->findOwnedRoomTenant($request, $id);
        if ($membership->contract_id !== null) {
            throw ValidationException::withMessages(['status' => 'Người ở gắn với hợp đồng phải được ghi nhận chuyển đi, không xóa lịch sử.']);
        }
        $membership->delete();

        return ApiResponse::success(null, 'Xóa người ở trong phòng thành công.');
    }

    private function findOwnedRoom(Request $request, int $id): Room
    {
        return Room::query()
            ->whereHas('boardingHouse', fn ($query) => $query->where('landlord_id', $request->user()->landlord->id))
            ->findOrFail($id);
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
