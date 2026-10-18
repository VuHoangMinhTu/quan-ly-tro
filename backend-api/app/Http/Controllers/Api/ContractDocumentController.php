<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\ContractDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractDocumentController extends Controller
{
    public function __construct(private ContractDocumentService $documents) {}

    public function preview(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->documents->preview($request->user(), $id), 'Xem trước văn bản hợp đồng thành công.');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success([...$this->documents->document($request->user(), $id)->toArray(), 'is_preview' => false], 'Lấy tài liệu hợp đồng thành công.');
    }

    public function finalize(Request $request, int $id): JsonResponse
    {
        $this->documents->finalize($request->user(), $id);

        return ApiResponse::success(null, 'Đã chốt dữ liệu và tài liệu hợp đồng.');
    }
}
