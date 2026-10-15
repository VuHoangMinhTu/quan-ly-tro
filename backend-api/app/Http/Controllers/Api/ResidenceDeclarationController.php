<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Residence\SaveResidenceDeclarationRequest;
use App\Http\Requests\Residence\UpdateResidenceStatusRequest;
use App\Models\ResidenceDeclaration;
use App\Services\BoardingHouseAccessService;
use App\Services\ResidenceDeclarationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResidenceDeclarationController extends Controller
{
    public function __construct(private BoardingHouseAccessService $access, private ResidenceDeclarationService $declarations) {}

    public function houses(Request $request): JsonResponse
    {
        $houses = $this->access->houses($request->user())->with('landlord')->orderBy('name')->get();

        return ApiResponse::success($houses->map(fn ($house) => [
            'id' => $house->id, 'name' => $house->name, 'address' => $house->address,
            'operational_role' => $this->access->role($request->user(), $house),
        ]), 'Lấy danh sách nhà trọ được phép khai báo cư trú thành công.');
    }

    public function rooms(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->access->house($request->user(), $id)->rooms()->orderBy('room_code')->get(), 'Lấy danh sách phòng thành công.');
    }

    public function context(Request $request, int $id): JsonResponse
    {
        $context = $this->declarations->context($request->user(), $id);
        if ($request->filled('contract_id')) {
            $contract = $context['room']->contracts()->findOrFail($request->integer('contract_id'));
            $active = $contract->occupants()->where('status', 'ACTIVE')->pluck('tenant_id')->map(fn ($value) => (int) $value);
            $context['suggested_declaration'] = [
                'contract_id' => $contract->id,
                'declarant_tenant_id' => $active->contains((int) $contract->tenant_id) ? $contract->tenant_id : null,
                'request_content' => 'Đăng ký tạm trú',
                'members' => $active->reject(fn ($value) => $value === (int) $contract->tenant_id)->map(fn ($tenant) => ['tenant_id' => $tenant, 'relationship_to_household_head' => ''])->values(),
            ];
        }

        return ApiResponse::success($context, 'Lấy dữ liệu khai báo cư trú thành công.');
    }

    public function index(Request $request): JsonResponse
    {
        $records = ResidenceDeclaration::query()->whereIn('boarding_house_id', $this->access->houses($request->user())->select('boarding_houses.id'))
            ->when($request->filled('room_id'), fn ($query) => $query->where('room_id', $request->integer('room_id')))
            ->with('room.boardingHouse')->latest('id')->get();
        $records->filter(fn (ResidenceDeclaration $record) => $record->snapshot === null)->load('declarant');
        $items = $records->map(fn (ResidenceDeclaration $record) => [
            'id' => $record->id, 'room_id' => $record->room_id, 'status' => $record->status,
            'declarant_name' => $record->snapshot !== null ? $record->snapshot['declarant']['full_name'] : $record->declarant?->full_name,
            'boarding_house_name' => $record->snapshot !== null ? $record->snapshot['residence']['boarding_house_name'] : $record->room->boardingHouse->name,
            'room_code' => $record->snapshot !== null ? $record->snapshot['residence']['room_code'] : $record->room->room_code,
            'is_frozen' => $record->snapshot !== null,
        ]);

        return ApiResponse::success($items, 'Lấy danh sách hồ sơ cư trú thành công.');
    }

    public function store(SaveResidenceDeclarationRequest $request): JsonResponse
    {
        $record = $this->declarations->save($request->user(), $request->validated());

        return ApiResponse::success(['id' => $record->id], 'Tạo hồ sơ cư trú nháp thành công.', 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $record = $this->declarations->find($request->user(), $id);
        $preview = $this->declarations->preview($record);
        $record->load('members', 'room.boardingHouse');

        return ApiResponse::success([
            'declaration' => $record, ...$preview,
            'operational_role' => $this->access->role($request->user(), $record->boardingHouse),
        ], 'Lấy hồ sơ cư trú thành công.');
    }

    public function update(SaveResidenceDeclarationRequest $request, int $id): JsonResponse
    {
        $this->declarations->save($request->user(), $request->validated(), $id);

        return ApiResponse::success(null, 'Cập nhật hồ sơ cư trú thành công.');
    }

    public function status(UpdateResidenceStatusRequest $request, int $id): JsonResponse
    {
        $this->declarations->changeStatus($request->user(), $id, $request->validated());

        return ApiResponse::success(null, 'Cập nhật trạng thái hồ sơ cư trú thành công.');
    }

    public function export(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->declarations->export($this->declarations->find($request->user(), $id)), 'Dữ liệu CT01 đã sẵn sàng để in.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        DB::transaction(function () use ($request, $id): void {
            $record = $this->declarations->find($request->user(), $id, true);
            if ($record->snapshot !== null || $record->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => 'Chỉ có thể xóa hồ sơ nháp chưa chốt.']);
            }
            $record->delete();
        });

        return ApiResponse::success(null, 'Xóa hồ sơ cư trú thành công.');
    }
}
