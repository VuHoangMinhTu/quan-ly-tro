<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\UtilityReading\StoreUtilityReadingRequest;
use App\Http\Requests\UtilityReading\UpdateUtilityReadingRequest;
use App\Models\UtilityMeter;
use App\Models\UtilityReading;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UtilityReadingController extends Controller
{
    public function index(Request $request, int $meterId): JsonResponse
    {
        return ApiResponse::success($this->meter($request, $meterId)->readings()->get(), 'Lấy danh sách chỉ số điện/nước thành công.');
    }

    public function store(StoreUtilityReadingRequest $request, int $meterId): JsonResponse
    {
        $meter = $this->meter($request, $meterId);
        $data = $request->validated();
        $this->validateSequence($meter, $data);
        $meter->readings()->create($data);

        return ApiResponse::success(null, 'Thêm chỉ số điện/nước thành công.', 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->reading($request, $id)->load('meter'), 'Lấy thông tin chỉ số điện/nước thành công.');
    }

    public function update(UpdateUtilityReadingRequest $request, int $id): JsonResponse
    {
        $reading = $this->reading($request, $id);
        $data = $request->validated();
        $this->validateSequence($reading->meter, $data, $reading->id);
        $reading->update($data);

        return ApiResponse::success(null, 'Cập nhật chỉ số điện/nước thành công.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->reading($request, $id)->delete();

        return ApiResponse::success(null, 'Xóa chỉ số điện/nước thành công.');
    }

    private function meter(Request $request, int $id): UtilityMeter
    {
        return UtilityMeter::whereHas('room.boardingHouse', fn ($query) => $query->where('landlord_id', $request->user()->landlord->id))->findOrFail($id);
    }

    private function reading(Request $request, int $id): UtilityReading
    {
        return UtilityReading::whereHas('meter.room.boardingHouse', fn ($query) => $query->where('landlord_id', $request->user()->landlord->id))->findOrFail($id);
    }

    private function validateSequence(UtilityMeter $meter, array $data, ?int $ignoreId = null): void
    {
        $readings = $meter->readings()->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId));
        if ($readings->clone()->whereDate('reading_date', $data['reading_date'])->exists()) {
            throw ValidationException::withMessages(['reading_date' => 'Ngày này đã có chỉ số điện/nước. Vui lòng chọn ngày khác.']);
        }
        $previous = $readings->clone()->where('reading_date', '<', $data['reading_date'])->orderByDesc('reading_date')->first();
        $next = $readings->clone()->where('reading_date', '>', $data['reading_date'])->orderBy('reading_date')->first();
        if ($previous && $data['reading_value'] < $previous->reading_value) {
            throw ValidationException::withMessages(['reading_value' => 'Chỉ số công tơ không được nhỏ hơn chỉ số trước đó.']);
        }
        if (! $previous && $data['reading_value'] < $meter->initial_reading) {
            throw ValidationException::withMessages(['reading_value' => 'Chỉ số đầu tiên không được nhỏ hơn chỉ số đầu của đồng hồ.']);
        }
        if ($next && $data['reading_value'] > $next->reading_value) {
            throw ValidationException::withMessages(['reading_value' => 'Chỉ số công tơ không được lớn hơn chỉ số của lần ghi kế tiếp.']);
        }
    }
}
