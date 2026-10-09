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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UtilityMeterController extends Controller
{
    public function index(Request $request, int $roomId): JsonResponse
    {
        $meters = $this->room($request, $roomId)->utilityMeters()->with(['service', 'latestReading'])->get();

        return ApiResponse::success($meters, 'Lấy danh sách đồng hồ điện/nước thành công.');
    }

    public function store(StoreUtilityMeterRequest $request, int $roomId): JsonResponse
    {
        DB::transaction(function () use ($request, $roomId): void {
            $room = $this->room($request, $roomId, true);
            $data = $request->validated();
            $this->validateMeter($room, $data);
            $room->utilityMeters()->create($data);
        });

        return ApiResponse::success(null, 'Tạo đồng hồ điện/nước thành công.', 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->meter($request, $id)->load(['room', 'service', 'latestReading']), 'Lấy thông tin đồng hồ điện/nước thành công.');
    }

    public function update(UpdateUtilityMeterRequest $request, int $id): JsonResponse
    {
        $meter = $this->meter($request, $id);

        DB::transaction(function () use ($request, $meter): void {
            $room = $this->room($request, $meter->room_id, true);
            $lockedMeter = $room->utilityMeters()->lockForUpdate()->findOrFail($meter->id);
            $data = $request->validated();
            $this->validateMeter($room, $data, $lockedMeter);
            $lockedMeter->update($data);
        });

        return ApiResponse::success(null, 'Cập nhật đồng hồ điện/nước thành công.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->meter($request, $id)->delete();

        return ApiResponse::success(null, 'Xóa đồng hồ điện/nước thành công.');
    }

    private function room(Request $request, int $id, bool $lockForUpdate = false): Room
    {
        // Share the room lock with service assignment so eligibility and duplicate checks stay valid until saved.
        return Room::whereHas('boardingHouse', fn ($query) => $query->where('landlord_id', $request->user()->landlord->id))
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->findOrFail($id);
    }

    private function meter(Request $request, int $id): UtilityMeter
    {
        return UtilityMeter::whereHas('room.boardingHouse', fn ($query) => $query->where('landlord_id', $request->user()->landlord->id))->findOrFail($id);
    }

    /**
     * @param  array{service_id: int, meter_code?: ?string, initial_reading: numeric, is_active?: bool}  $data
     */
    private function validateMeter(Room $room, array $data, ?UtilityMeter $meter = null): void
    {
        $service = Service::query()->lockForUpdate()->findOrFail($data['service_id']);
        if ($service->boarding_house_id !== $room->boarding_house_id) {
            throw ValidationException::withMessages(['service_id' => 'Dịch vụ phải thuộc cùng nhà trọ với phòng.']);
        }
        if (! in_array($service->billing_method, ['PER_UNIT', 'TIERED'], true)) {
            throw ValidationException::withMessages(['service_id' => 'Đồng hồ chỉ dùng cho dịch vụ tính theo đơn vị hoặc bậc thang.']);
        }

        $isActive = $data['is_active'] ?? $meter?->is_active ?? true;
        $requiresAssignment = $meter === null
            || (int) $meter->service_id !== $service->id
            || ($isActive && ! $meter->is_active);

        // Keep historical meters editable/deactivatable after unassignment; require eligibility for new use.
        if ($requiresAssignment) {
            if (! $service->is_active) {
                throw ValidationException::withMessages(['service_id' => 'Dịch vụ phải đang được áp dụng.']);
            }

            if (! $room->services()->wherePivot('is_active', true)->whereKey($service->id)->exists()) {
                throw ValidationException::withMessages(['service_id' => 'Dịch vụ phải được gán và đang áp dụng cho phòng này.']);
            }
        }

        if ($isActive && UtilityMeter::where('room_id', $room->id)->where('service_id', $service->id)->where('is_active', true)->when($meter, fn ($query) => $query->whereKeyNot($meter->id))->exists()) {
            throw ValidationException::withMessages(['service_id' => 'Phòng đã có đồng hồ đang hoạt động cho dịch vụ này.']);
        }
    }
}
