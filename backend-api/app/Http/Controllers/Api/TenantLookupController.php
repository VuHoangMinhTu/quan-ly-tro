<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\TenantLookupRequest;
use App\Models\Landlord;
use App\Models\Tenant;
use App\Services\BoardingHouseAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class TenantLookupController extends Controller
{
    public function __construct(private BoardingHouseAccessService $access) {}

    public function options(TenantLookupRequest $request): JsonResponse
    {
        $context = $request->filled('context_room_id') ? $this->access->room($request->user(), $request->integer('context_room_id'))->load('boardingHouse') : null;
        $houses = $this->access->houses($request->user())
            ->when($context, fn ($query) => $query->where('landlord_id', $context->boardingHouse->landlord_id));
        $rooms = [];
        if ($request->filled('boarding_house_id')) {
            $house = (clone $houses)->findOrFail($request->integer('boarding_house_id'));
            $rooms = $house->rooms()->orderBy('room_code')->get(['id', 'room_code']);
        }

        return ApiResponse::success(['houses' => $houses->orderBy('name')->get(['id', 'name']), 'rooms' => $rooms,
            'can_create' => Landlord::query()->where('user_id', $request->user()->id)->when($context, fn ($query) => $query->whereKey($context->boardingHouse->landlord_id))->exists()]);
    }

    public function index(TenantLookupRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $ownerIds = Landlord::query()->where('user_id', $request->user()->id)->pluck('id');
        $houses = $this->access->houses($request->user());
        $context = $request->filled('context_room_id') ? $this->access->room($request->user(), $request->integer('context_room_id'))->load('boardingHouse') : null;
        $house = $request->filled('boarding_house_id') ? (clone $houses)->findOrFail($request->integer('boarding_house_id')) : null;
        $room = $request->filled('room_id') ? $this->access->room($request->user(), $request->integer('room_id')) : null;
        abort_if($house && $room && $room->boarding_house_id !== $house->id, 404);
        abort_if($context && $house && $context->boardingHouse->landlord_id !== $house->landlord_id, 404);
        abort_if($context && $room && $context->boardingHouse->landlord_id !== $room->boardingHouse->landlord_id, 404);

        // History is never proof of current occupancy. Ignore corrupt cross-workspace links.
        $validMembership = fn (Builder $membership) => $membership->whereHas('room.boardingHouse', fn (Builder $query) => $query->whereColumn('boarding_houses.landlord_id', 'tenants.landlord_id'));
        $active = fn (Builder $membership) => $validMembership($membership)->where('status', 'ACTIVE');
        $visibleMembership = fn (Builder $membership) => $validMembership($membership)->whereHas('room', fn ($query) => $query->whereIn('boarding_house_id', (clone $houses)->select('boarding_houses.id')));
        $query = Tenant::query()->where(function (Builder $visible) use ($ownerIds, $visibleMembership): void {
            $visible->whereIn('landlord_id', $ownerIds)->orWhereHas('roomTenants', $visibleMembership);
        });
        if ($context) {
            $query->where('landlord_id', $context->boardingHouse->landlord_id);
        }
        if (($filters['scope'] ?? '') === 'ACTIVE_ROOM') {
            abort_unless($context, 422, 'Vui lòng chọn phòng trước khi chọn người thuê.');
            $query->whereHas('roomTenants', fn ($membership) => $active($membership)->where('room_id', $context->id));
        }
        $status = $filters['occupancy_status'] ?? null;
        if ($status === 'ACTIVE') {
            $query->whereHas('roomTenants', $active);
        } elseif ($status === 'MOVED_OUT') {
            $query->whereHas('roomTenants', $validMembership)->whereDoesntHave('roomTenants', $active);
        } elseif ($status === 'UNASSIGNED') {
            $query->whereDoesntHave('roomTenants', $validMembership);
        }
        if ($house || $room) {
            $query->whereHas('roomTenants', function ($membership) use ($validMembership, $house, $room, $status): void {
                $validMembership($membership);
                if ($status !== 'MOVED_OUT') {
                    $membership->where('status', 'ACTIVE');
                }
                if ($house) {
                    $membership->whereHas('room', fn ($query) => $query->where('boarding_house_id', $house->id));
                }
                if ($room) {
                    $membership->where('room_id', $room->id);
                }
            });
        }
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(function ($query) use ($term, $ownerIds): void {
                $query->where('full_name', 'like', $term)->orWhere('phone', 'like', $term)
                    ->orWhere(fn ($identity) => $identity->whereIn('landlord_id', $ownerIds)->where('identity_number', 'like', $term));
            });
        }
        if (isset($filters['ids'])) {
            $query->whereIn('id', $filters['ids']);
        }
        if (! empty($filters['exclude_ids'])) {
            $query->whereNotIn('id', $filters['exclude_ids']);
        }
        $page = $query->select(['id', 'full_name', 'phone'])->withExists(['roomTenants as has_active' => $active, 'roomTenants as has_history' => $validMembership])
            ->with(['roomTenants' => fn ($membership) => $membership->where('status', 'ACTIVE')->whereHas('room', fn ($query) => $query->whereIn('boarding_house_id', (clone $houses)->select('boarding_houses.id')))
                ->whereHas('room.boardingHouse.landlord.tenants', fn ($query) => $query->whereColumn('tenants.id', 'room_tenants.tenant_id'))
                ->orderByDesc('id')->limit(1)->with(['room:id,boarding_house_id,room_code', 'room.boardingHouse:id,name'])])
            ->orderBy('full_name')->orderBy('id')->paginate($filters['per_page'] ?? 20);
        $rows = $page->getCollection()->map(function ($tenant): array {
            $residence = $tenant->roomTenants->first()?->room;

            return ['id' => $tenant->id, 'full_name' => $tenant->full_name, 'phone' => $tenant->phone,
                'occupancy_status' => $tenant->has_active ? 'ACTIVE' : ($tenant->has_history ? 'MOVED_OUT' : 'UNASSIGNED'),
                'current_residence' => $residence ? ['room_id' => $residence->id, 'room_code' => $residence->room_code, 'boarding_house_id' => $residence->boarding_house_id, 'boarding_house_name' => $residence->boardingHouse->name] : null];
        });

        return ApiResponse::success(['tenants' => $rows, 'total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
            'can_search_identity' => $context ? $ownerIds->contains($context->boardingHouse->landlord_id) : $ownerIds->isNotEmpty()]);
    }
}
