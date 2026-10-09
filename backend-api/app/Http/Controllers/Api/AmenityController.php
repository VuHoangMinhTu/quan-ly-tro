<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Amenity\StoreAmenityRequest;
use App\Models\Amenity;
use Illuminate\Http\JsonResponse;

class AmenityController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            Amenity::query()->orderBy('name')->get(),
            'Lấy danh sách tiện nghi thành công.'
        );
    }

    public function store(StoreAmenityRequest $request): JsonResponse
    {
        $amenity = Amenity::query()->create($request->validated());

        // The generated id is required immediately so the room editor can select
        // the new master amenity without closing and reopening the modal.
        return ApiResponse::success([
            'amenity' => $amenity->only(['id', 'name']),
        ], 'Tạo tiện nghi thành công.', 201);
    }
}
