<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contract\SaveHandoverItemsRequest;
use App\Services\ContractHandoverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractHandoverController extends Controller
{
    public function __construct(private ContractHandoverService $handovers) {}

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->handovers->find($request->user(), $id), 'Lấy biên bản bàn giao thành công.');
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $this->handovers->create($request->user(), $id);

        return ApiResponse::success(null, 'Tạo biên bản bàn giao thành công.', 201);
    }

    public function update(SaveHandoverItemsRequest $request, int $id): JsonResponse
    {
        $this->handovers->update($request->user(), $id, $request->validated('items'));

        return ApiResponse::success(null, 'Cập nhật biên bản bàn giao thành công.');
    }

    public function confirm(Request $request, int $id): JsonResponse
    {
        $this->handovers->confirm($request->user(), $id);

        return ApiResponse::success(null, 'Xác nhận bàn giao thành công.');
    }
}
