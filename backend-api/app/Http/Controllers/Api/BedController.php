<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\BoardingHouseAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BedController extends Controller
{
    public function __construct(private BoardingHouseAccessService $access) {}

    public function index(Request $request, int $roomId): JsonResponse
    {
        $room = $this->access->room($request->user(), $roomId);

        return ApiResponse::success($room->beds()->orderBy('bed_code')->get(), 'Lấy danh sách giường thành công.');
    }

    public function store(Request $request, int $roomId): JsonResponse
    {
        $room = $this->access->room($request->user(), $roomId);
        $this->access->house($request->user(), $room->boarding_house_id);
        $data = $request->validate([
            'bed_code' => ['required', 'string', 'max:50', Rule::unique('beds')->where('room_id', $roomId)],
            'floor_label' => ['nullable', 'string', 'max:100'],
        ]);
        $bed = DB::transaction(fn () => $room->beds()->create($data));

        return ApiResponse::success(['id' => $bed->id], 'Tạo giường thành công.', 201);
    }
}
