<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreTenantRequest;
use App\Http\Requests\Tenant\TenantIndexRequest;
use App\Http\Requests\Tenant\UpdateTenantRequest;
use App\Models\BoardingHouse;
use App\Models\Landlord;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function index(TenantIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $landlordId = $request->user()->landlord->id;

        if (! empty($filters['boarding_house_id'])) {
            BoardingHouse::query()->where('landlord_id', $landlordId)
                ->findOrFail($filters['boarding_house_id']);
        }
        if (! empty($filters['room_id'])) {
            Room::query()->whereHas('boardingHouse', fn (Builder $query) => $query->where('landlord_id', $landlordId))
                ->findOrFail($filters['room_id']);
        }

        $query = $this->landlordTenants($request);

        if (($filters['q'] ?? '') !== '') {
            $term = '%'.$filters['q'].'%';
            $query->where(function (Builder $search) use ($term): void {
                $search->where('full_name', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('identity_number', 'like', $term);
            });
        }

        // Only valid rooms of this landlord count as residence, never contract history.
        $ownedMembership = fn (Builder $membership) => $membership->whereHas(
            'room.boardingHouse', fn (Builder $house) => $house->where('landlord_id', $landlordId)
        );
        $activeMembership = fn (Builder $membership) => $ownedMembership($membership)->where('status', 'ACTIVE');

        $occupancyStatus = $filters['occupancy_status'] ?? null;
        if ($occupancyStatus === 'ACTIVE') {
            $query->whereHas('roomTenants', $activeMembership);
        } elseif ($occupancyStatus === 'MOVED_OUT') {
            $query->whereHas('roomTenants', $ownedMembership)
                ->whereDoesntHave('roomTenants', $activeMembership);
        } elseif ($occupancyStatus === 'UNASSIGNED') {
            $query->whereDoesntHave('roomTenants', $ownedMembership);
        }

        // All residence filters must match ONE active membership, not separate historical rows.
        if (! empty($filters['boarding_house_id']) || ! empty($filters['room_id']) || ! empty($filters['role'])) {
            $query->whereHas('roomTenants', function (Builder $membership) use ($activeMembership, $filters): void {
                $activeMembership($membership);
                if (! empty($filters['boarding_house_id'])) {
                    $membership->whereHas('room', fn (Builder $room) => $room->where('boarding_house_id', $filters['boarding_house_id']));
                }
                if (! empty($filters['room_id'])) {
                    $membership->where('room_id', $filters['room_id']);
                }
                if (! empty($filters['role'])) {
                    $membership->where('is_primary', $filters['role'] === 'PRIMARY');
                }
            });
        }

        $contractStatus = $filters['contract_status'] ?? null;
        $ownedContracts = fn (Builder $contracts) => $contracts->whereHas(
            'room.boardingHouse', fn (Builder $house) => $house->where('landlord_id', $landlordId)
        );
        if ($contractStatus === 'NONE') {
            $query->whereDoesntHave('contracts', $ownedContracts);
        } elseif ($contractStatus) {
            $query->whereHas('contracts', fn (Builder $contracts) => $ownedContracts($contracts)->where('status', $contractStatus));
        }

        $tenants = $query->with(['roomTenants' => function (HasMany $membership) use ($activeMembership): void {
            $activeMembership($membership->getQuery());
            $membership->orderByDesc('move_in_date')->orderByDesc('id')
                ->limit(1)->with(['room:id,boarding_house_id,room_code', 'room.boardingHouse:id,name']);
        }])->latest()->orderByDesc('tenants.id')->get()->map(function (Tenant $tenant): array {
            $residence = $tenant->roomTenants->first();
            $data = $tenant->attributesToArray();
            $data['current_residence'] = $residence ? [
                'room_id' => $residence->room->id,
                'room_code' => $residence->room->room_code,
                'boarding_house_id' => $residence->room->boardingHouse->id,
                'boarding_house_name' => $residence->room->boardingHouse->name,
                'is_primary' => $residence->is_primary,
            ] : null;

            return $data;
        });

        return ApiResponse::success($tenants, 'Lấy danh sách người thuê thành công.');
    }

    // tương đương method POST bên rest api
    public function store(StoreTenantRequest $request): JsonResponse
    {
        $tenant = $this->landlordTenants($request)->create($request->validated());

        return ApiResponse::success(null, 'Tạo người thuê thành công.', 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success(
            $this->findOwnedTenant($request, $id),
            'Lấy thông tin người thuê thành công.'
        );
    }

    public function update(UpdateTenantRequest $request, int $id): JsonResponse
    {
        $tenant = $this->findOwnedTenant($request, $id);
        $tenant->update($request->validated());

        return ApiResponse::success(null, 'Cập nhật người thuê thành công.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->findOwnedTenant($request, $id)->delete();

        return ApiResponse::success(null, 'Xóa người thuê thành công.');
    }

    private function landlordTenants(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Landlord $landlord */
        $landlord = $user->landlord;

        return $landlord->tenants();
    }

    private function findOwnedTenant(Request $request, int $id): Tenant
    {
        return $this->landlordTenants($request)->findOrFail($id);
    }
}
