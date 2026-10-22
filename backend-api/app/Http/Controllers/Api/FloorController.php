<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\BoardingHouseAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FloorController extends Controller
{
    public function __construct(private BoardingHouseAccessService $access) {}

    public function index(Request $request, int $boardingHouseId): JsonResponse
    {
        $house = $this->access->house($request->user(), $boardingHouseId);

        return ApiResponse::success($house->floors()->orderBy('sort_order')->orderBy('name')->get(), 'Lấy danh sách tầng thành công.');
    }

    public function store(Request $request, int $boardingHouseId): JsonResponse
    {
        $house = $this->access->house($request->user(), $boardingHouseId);
        if (is_string($request->input('name'))) $request->merge(['name' => trim($request->input('name'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('floors')->where('boarding_house_id', $house->id)],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $floor = $house->floors()->create(['name' => $data['name'], 'sort_order' => $data['sort_order'] ?? 0]);

        return ApiResponse::success($floor, 'Tạo tầng thành công.', 201);
    }
}
