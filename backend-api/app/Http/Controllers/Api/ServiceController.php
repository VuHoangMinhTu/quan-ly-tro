<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Service\StoreServiceRequest;
use App\Http\Requests\Service\UpdateServiceRequest;
use App\Models\BoardingHouse;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceController extends Controller
{
    public function index(Request $request, int $boardingHouseId): JsonResponse
    {
        $services = $this->findOwnedBoardingHouse($request, $boardingHouseId)
            ->services()
            ->with('priceTiers')
            ->latest()
            ->get();

        return ApiResponse::success($services, 'Lấy danh sách dịch vụ thành công.');
    }

    public function store(StoreServiceRequest $request, int $boardingHouseId): JsonResponse
    {
        $service = $this->findOwnedBoardingHouse($request, $boardingHouseId)
            ->services()
            ->create($request->validated());

        return ApiResponse::success(null, 'Tạo dịch vụ thành công.', 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success(
            $this->findOwnedService($request, $id)->load('priceTiers'),
            'Lấy thông tin dịch vụ thành công.'
        );
    }

    public function update(UpdateServiceRequest $request, int $id): JsonResponse
    {
        $boardingHouseId = $this->findOwnedService($request, $id)->boarding_house_id;

        DB::transaction(function () use ($request, $id, $boardingHouseId): void {
            // Share the assignment lock before snapshot reads, so conflict checks see assignments committed while waiting.
            BoardingHouse::query()->whereKey($boardingHouseId)->lockForUpdate()->firstOrFail();
            $service = $this->findOwnedService($request, $id, true);
            $data = $request->validated();
            $this->ensureNoUtilityTypeConflict($service, $data);
            $service->update($data);
        }, 3);

        return ApiResponse::success(null, 'Cập nhật dịch vụ thành công.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->findOwnedService($request, $id)->delete();

        return ApiResponse::success(null, 'Xóa dịch vụ thành công.');
    }
    //

    private function findOwnedBoardingHouse(Request $request, int $id): BoardingHouse
    {
        return $request->user()->landlord->boardingHouses()->findOrFail($id);
    }

    /** @param array<string, mixed> $data */
    private function ensureNoUtilityTypeConflict(Service $service, array $data): void
    {
        $type = $data['type'];
        $isActive = (bool) ($data['is_active'] ?? $service->is_active);
        $typeChanged = $type !== $service->type;
        $activeChanged = $isActive !== $service->is_active;

        if (! $isActive || ! in_array($type, ['ELECTRICITY', 'WATER'], true) || (! $typeChanged && ! $activeChanged)) {
            return;
        }

        $hasConflict = $service->rooms()
            ->wherePivot('is_active', true)
            ->whereHas('services', function ($query) use ($service, $type): void {
                $query->where('services.id', '!=', $service->id)
                    ->where('services.type', $type)
                    ->where('services.is_active', true)
                    ->where('room_services.is_active', true);
            })
            ->exists();

        if ($hasConflict) {
            $field = $typeChanged ? 'type' : 'is_active';
            $label = $type === 'ELECTRICITY' ? 'điện' : 'nước';
            throw ValidationException::withMessages([
                $field => "Không thể thay đổi dịch vụ: phòng đang áp dụng một dịch vụ {$label} khác.",
            ]);
        }
    }

    private function findOwnedService(Request $request, int $id, bool $lockForUpdate = false): Service
    {
        return Service::query()
            ->whereHas('boardingHouse', fn ($query) => $query->where('landlord_id', $request->user()->landlord->id))
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->findOrFail($id);
    }
}
