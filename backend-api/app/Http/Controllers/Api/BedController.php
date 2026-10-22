<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\BoardingHouseAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Models\Bed;

class BedController extends Controller
{
    public function __construct(private BoardingHouseAccessService $access) {}

    public function index(Request $request, int $roomId): JsonResponse
    {
        $room = $this->access->room($request->user(), $roomId);

        return ApiResponse::success($room->beds()->with(['roomTenants' => fn ($query) => $query->where('status', 'ACTIVE')->with('tenant')])->orderBy('bed_code')->get(), 'Lấy danh sách giường thành công.');
    }

    public function store(Request $request, int $roomId): JsonResponse
    {
        $room = $this->access->room($request->user(), $roomId);
        $this->access->house($request->user(), $room->boarding_house_id);
        if ($room->room_type !== 'DORMITORY') {
            throw ValidationException::withMessages(['room_type' => 'Chỉ phòng KTX mới có giường.']);
        }
        $data = $request->validate([
            'bed_code' => ['required', 'string', 'max:50', Rule::unique('beds')->where('room_id', $roomId)],
            'floor_label' => ['nullable', 'string', 'max:100'],
            'monthly_rent' => ['nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', Rule::in(['AVAILABLE', 'MAINTENANCE'])],
            'note' => ['nullable', 'string'],
        ]);
        $bed = DB::transaction(fn () => $room->beds()->create($data));

        return ApiResponse::success(['id' => $bed->id], 'Tạo giường thành công.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $bed = Bed::query()->whereHas('room', fn ($query) => $query->whereIn('boarding_house_id', $this->access->houses($request->user())->select('boarding_houses.id')))->findOrFail($id);
        $this->access->house($request->user(), $bed->room->boarding_house_id);
        $data = $request->validate([
            'bed_code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('beds')->where('room_id', $bed->room_id)->ignore($bed->id)],
            'monthly_rent' => ['nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', Rule::in(['AVAILABLE', 'OCCUPIED', 'MAINTENANCE'])],
            'note' => ['nullable', 'string'],
        ]);
        if (isset($data['status'])) {
            $occupied = $bed->roomTenants()->where('status', 'ACTIVE')->exists();
            if ($occupied !== ($data['status'] === 'OCCUPIED')) {
                throw ValidationException::withMessages(['status' => 'Trạng thái đang sử dụng được quản lý theo người ở, không thể đổi thủ công.']);
            }
        }
        $bed->update($data);

        return ApiResponse::success($bed->fresh(), 'Cập nhật giường thành công.');
    }
}
