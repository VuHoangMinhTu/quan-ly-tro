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

        $roomTenant->update($data);

        return ApiResponse::success(null, 'Cập nhật người ở trong phòng thành công.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->findOwnedRoomTenant($request, $id)->delete();

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
