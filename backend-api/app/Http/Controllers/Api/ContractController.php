<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contract\ChangeContractRoomRequest;
use App\Http\Requests\Contract\ContractScheduleRequest;
use App\Http\Requests\Contract\ContractStatusRequest;
use App\Http\Requests\Contract\MoveOutContractOccupantRequest;
use App\Http\Requests\Contract\StoreContractRequest;
use App\Http\Requests\Contract\UpdateContractRequest;
use App\Services\BoardingHouseAccessService;
use App\Services\ContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class ContractController extends Controller
{
    public function __construct(private ContractService $contracts, private BoardingHouseAccessService $access) {}

    public function context(Request $request, int $roomId): JsonResponse
    {
        return ApiResponse::success($this->contracts->context($request->user(), $roomId), 'Lấy thông tin tạo hợp đồng thành công.');
    }

    public function index(Request $request, int $roomId): JsonResponse
    {
        $records = $this->access->room($request->user(), $roomId)->contracts()
            ->with(['tenant:id,full_name', 'bed:id,bed_code,floor_label'])->latest('id')->get()->map(function ($contract): array {
                // List payloads never expose identity, address or the full signing snapshot.
                return [...Arr::only($contract->toArray(), ['id', 'room_id', 'tenant_id', 'contract_type', 'bed_id', 'contract_code', 'start_date', 'end_date', 'signed_date', 'monthly_rent', 'deposit_amount', 'status']),
                    'bed' => $contract->bed?->only(['id', 'bed_code', 'floor_label']),
                    'tenant' => ['id' => $contract->tenant_id, 'full_name' => $contract->signing_snapshot['tenant']['full_name'] ?? $contract->tenant?->full_name]];
            });

        return ApiResponse::success($records, 'Lấy danh sách hợp đồng thành công.');
    }

    public function store(StoreContractRequest $request, int $roomId): JsonResponse
    {
        $contract = $this->contracts->save($request->user(), $roomId, $request->validated());

        return ApiResponse::success(['id' => $contract->id], 'Tạo hợp đồng thành công.', 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->contracts->detail($request->user(), $id), 'Lấy thông tin hợp đồng thành công.');
    }

    public function update(UpdateContractRequest $request, int $id): JsonResponse
    {
        $contract = $this->contracts->find($request->user(), $id);
        $this->contracts->save($request->user(), $contract->room_id, $request->validated(), $id);

        return ApiResponse::success(null, 'Cập nhật hợp đồng thành công.');
    }

    public function status(ContractStatusRequest $request, int $id): JsonResponse
    {
        $contract = $this->contracts->find($request->user(), $id);
        $this->contracts->save($request->user(), $contract->room_id, $request->validated(), $id);

        return ApiResponse::success(null, 'Cập nhật trạng thái hợp đồng thành công.');
    }

    public function moveOut(MoveOutContractOccupantRequest $request, int $id, int $occupantId): JsonResponse
    {
        $this->contracts->moveOut($request->user(), $id, $occupantId, $request->validated('move_out_date'));

        return ApiResponse::success(null, 'Đã ghi nhận người ở chuyển đi.');
    }

    public function changeRoom(ChangeContractRoomRequest $request, int $id): JsonResponse
    {
        $this->contracts->changeRoom($request->user(), $id, $request->integer('room_id'));

        return ApiResponse::success(null, 'Đã đổi phòng cho hợp đồng nháp.');
    }

    public function schedule(ContractScheduleRequest $request, int $id): JsonResponse
    {
        $house = $this->access->house($request->user(), $id);
        $house->update($request->validated());

        return ApiResponse::success(null, 'Cập nhật lịch thanh toán mặc định thành công.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->contracts->remove($request->user(), $id);

        return ApiResponse::success(null, 'Xóa hợp đồng thành công.');
    }
}
