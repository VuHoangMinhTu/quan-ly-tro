<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contract\AdjustContractServiceRequest;
use App\Http\Requests\Contract\SaveContractServicesRequest;
use App\Http\Requests\Contract\StopContractServiceRequest;
use App\Services\ContractServiceTerms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractServiceController extends Controller
{
    public function __construct(private ContractServiceTerms $terms) {}

    public function index(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->terms->listing($request->user(), $id), 'Lấy dịch vụ hợp đồng thành công.');
    }

    public function update(SaveContractServicesRequest $request, int $id): JsonResponse
    {
        $this->terms->draft($request->user(), $id, $request->validated());

        return ApiResponse::success(null, 'Cập nhật dịch vụ hợp đồng thành công.');
    }

    public function adjust(AdjustContractServiceRequest $request, int $id): JsonResponse
    {
        $this->terms->adjust($request->user(), $id, $request->validated());

        return ApiResponse::success(null, 'Điều chỉnh dịch vụ hợp đồng thành công.', 201);
    }

    public function stop(StopContractServiceRequest $request, int $id, int $termId): JsonResponse
    {
        $this->terms->stop($request->user(), $id, $termId, $request->validated());

        return ApiResponse::success(null, 'Đã ngừng áp dụng dịch vụ từ kỳ đã chọn.');
    }
}
