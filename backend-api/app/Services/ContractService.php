<?php

namespace App\Services;

use App\Models\BoardingHouse;
use App\Models\Contract;
use App\Models\Room;
use App\Models\RoomTenant;
use App\Models\Tenant;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContractService
{
    private const CORE = ['tenant_id', 'contract_code', 'start_date', 'end_date', 'signed_date', 'monthly_rent', 'deposit_amount', 'lessor_representative_user_id', 'invoice_issue_day_from', 'invoice_issue_day_to', 'payment_due_day', 'occupant_tenant_ids'];

    public function __construct(private BoardingHouseAccessService $access) {}

    public function find(User $user, int $id): Contract
    {
        return Contract::query()->whereHas('room', fn ($query) => $query->whereIn('boarding_house_id', $this->access->houses($user)->select('boarding_houses.id')))->findOrFail($id);
    }

    public function context(User $user, int $roomId): array
    {
        $room = $this->access->room($user, $roomId)->load('boardingHouse');
        $services = $this->eligibleServices($room);
        $meters = $room->utilityMeters()->where('is_active', true)->whereIn('service_id', $services->pluck('id'))
            ->whereHas('service', fn ($query) => $query->whereIn('type', ['ELECTRICITY', 'WATER']))->with(['service', 'latestReading'])->get();

        return [
            'room' => $room, 'operational_role' => $this->access->role($user, $room->boardingHouse),
            'tenants' => Tenant::query()->where('landlord_id', $room->boardingHouse->landlord_id)->orderBy('full_name')->get(['id', 'full_name', 'phone']),
            'representatives' => $this->representatives($room->boardingHouse),
            'services' => $services,
            'occupants' => $room->roomTenants()->where('status', 'ACTIVE')->with('tenant:id,full_name')->get(),
            'utility_openings' => $meters->map(fn ($meter) => [
                'utility_meter_id' => $meter->id, 'service_id' => $meter->service_id, 'service_name' => $meter->service->name,
                'unit' => $meter->service->unit, 'opening_reading' => $meter->latestReading?->reading_value ?? $meter->initial_reading,
                'reading_date' => $meter->latestReading?->reading_date?->format('Y-m-d') ?? now()->toDateString(),
            ]),
        ];
    }

    private function eligibleServices(Room $room): Collection
    {
        return $room->services()->wherePivot('is_active', true)->where('services.is_active', true)
            ->where('services.boarding_house_id', $room->boarding_house_id)->with('priceTiers')->get();
    }

    private function representatives(BoardingHouse $house): array
    {
        $house->loadMissing('landlord.user');
        $owner = $house->landlord->user;
        $managers = $house->managers()->with('landlord')->get();

        return collect([$owner])->merge($managers)->filter()->unique('id')->map(function (User $person) use ($owner): array {
            $role = (string) $person->id === (string) $owner?->id ? 'OWNER' : 'MANAGER';

            return ['user_id' => $person->id, 'full_name' => $person->name, 'role' => $role,
                'title' => $role === 'OWNER' ? 'Chủ trọ' : 'Quản lý', 'phone' => $person->landlord?->phone];
        })->values()->all();
    }

    public function save(User $user, int $roomId, array $data, ?int $id = null): Contract
    {
        return DB::transaction(function () use ($user, $roomId, $data, $id): Contract {
            $room = $this->access->room($user, $roomId);
            $house = $this->access->house($user, $room->boarding_house_id, true);
            // All activation decisions for this room share a lock, including overlap/capacity.
            $room = Room::query()->lockForUpdate()->findOrFail($roomId);
            $contract = $id === null ? new Contract(['room_id' => $roomId, 'status' => 'DRAFT', 'deposit_amount' => 0]) : $this->find($user, $id);
            abort_unless((int) $contract->room_id === $roomId, 404);
            if ($contract->exists && $contract->status !== 'DRAFT') {
                foreach (self::CORE as $key) {
                    if (array_key_exists($key, $data) && ! $this->sameValue($key, $contract, $data[$key])) {
                        throw ValidationException::withMessages([$key => 'Điều khoản hợp đồng đã chốt không được thay đổi. Vui lòng lập hợp đồng mới khi cần điều chỉnh.']);
                    }
                }
                if (array_key_exists('service_ids', $data) || array_key_exists('utility_openings', $data)) {
                    throw ValidationException::withMessages(['status' => 'Dịch vụ và chỉ số đầu của hợp đồng đã chốt không được thay đổi.']);
                }
                if (! in_array($contract->status, ['ACTIVE'], true) && array_key_exists('note', $data) && $data['note'] !== $contract->note) {
                    throw ValidationException::withMessages(['note' => 'Hợp đồng đã kết thúc chỉ được xem.']);
                }
                $this->transition($contract, $data['status'] ?? $contract->status, $data['move_out_date'] ?? null);
                if (array_key_exists('note', $data) && $contract->status === 'ACTIVE') {
                    $contract->note = $data['note'];
                }
                $contract->save();

                return $contract;
            }

            $tenantId = $data['tenant_id'] ?? $contract->tenant_id;
            Tenant::query()->where('landlord_id', $house->landlord_id)->findOrFail($tenantId);
            $isNew = ! $contract->exists;
            if ($isNew) {
                foreach (['invoice_issue_day_from', 'invoice_issue_day_to', 'payment_due_day'] as $key) {
                    if (! array_key_exists($key, $data)) {
                        $data[$key] = $house->{'default_'.$key};
                    }
                }
            }
            $contract->fill(Arr::only($data, [...self::CORE, 'note']));
            $selectedPeople = $contract->occupant_tenant_ids ?? [];
            if (count($selectedPeople) !== count(array_unique($selectedPeople))) {
                throw ValidationException::withMessages(['occupant_tenant_ids' => 'Danh sách người ở không được trùng lặp.']);
            }
            $allIds = array_values(array_unique([(int) $tenantId, ...array_map('intval', $selectedPeople)]));
            if (Tenant::query()->where('landlord_id', $house->landlord_id)->whereIn('id', $allIds)->count() !== count($allIds)) {
                throw ValidationException::withMessages(['occupant_tenant_ids' => 'Người ở cùng phải thuộc workspace của nhà trọ.']);
            }
            $contract->occupant_tenant_ids = array_values(array_filter($allIds, fn ($tenant) => $tenant !== (int) $tenantId));
            $representatives = collect($this->representatives($house));
            $representative = $representatives->firstWhere('user_id', $contract->lessor_representative_user_id);
            if ($contract->lessor_representative_user_id !== null && ! $representative) {
                throw ValidationException::withMessages(['lessor_representative_user_id' => 'Người đại diện không còn quyền vận hành nhà trọ này.']);
            }
            $target = $data['status'] ?? 'DRAFT';
            if (! in_array($target, ['DRAFT', 'ACTIVE'], true)) {
                throw ValidationException::withMessages(['status' => 'Hợp đồng nháp chỉ có thể lưu nháp hoặc kích hoạt.']);
            }
            $contract->status = 'DRAFT';
            $contract->save();
            if ($isNew || array_key_exists('service_ids', $data)) {
                $this->saveServices($contract, $room, $data['service_ids'] ?? $this->eligibleServices($room)->pluck('id')->all());
            }
            if ($isNew || array_key_exists('utility_openings', $data)) {
                $this->saveOpenings($contract, $room, $data['utility_openings'] ?? $this->context($user, $roomId)['utility_openings']->all());
            }
            if ($target === 'ACTIVE') {
                $this->activate($contract, $room, $house, $representative);
            }

            return $contract;
        }, 3);
    }

    private function sameValue(string $key, Contract $contract, mixed $value): bool
    {
        if (in_array($key, ['start_date', 'end_date', 'signed_date'], true)) {
            return $contract->{$key}?->format('Y-m-d') === $value;
        }
        if (in_array($key, ['monthly_rent', 'deposit_amount'], true) && $value !== null && $contract->{$key} !== null) {
            return BigDecimal::of((string) $contract->{$key})->isEqualTo((string) $value);
        }
        if ($key === 'occupant_tenant_ids') {
            return array_map('intval', $contract->{$key} ?? []) === array_map('intval', $value ?? []);
        }

        return (string) $contract->{$key} === (string) $value;
    }

    private function saveServices(Contract $contract, Room $room, array $ids): void
    {
        $services = $this->eligibleServices($room)->whereIn('id', $ids);
        if ($services->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['service_ids' => 'Chỉ được chọn dịch vụ đang áp dụng cho phòng này.']);
        }
        $contract->agreedServices()->delete();
        foreach ($services as $service) {
            $contract->agreedServices()->create([
                'service_id' => $service->id, 'service_name' => $service->name, 'service_type' => $service->type,
                'billing_method' => $service->billing_method, 'unit' => $service->unit, 'base_price' => $service->base_price,
                'vat_rate' => $service->vat_rate, 'pricing_snapshot' => $service->priceTiers->map->only(['from_quantity', 'to_quantity', 'unit_price', 'tier_order'])->all(),
                'is_active' => true,
            ]);
        }
    }

    private function saveOpenings(Contract $contract, Room $room, array $openings): void
    {
        $meters = $room->utilityMeters()->where('is_active', true)->whereIn('service_id', $this->eligibleServices($room)->pluck('id'))
            ->whereHas('service', fn ($query) => $query->whereIn('type', ['ELECTRICITY', 'WATER']))->with('service')->get()->keyBy('id');
        $contract->utilityOpenings()->delete();
        foreach ($openings as $index => $opening) {
            $meter = $meters->get($opening['utility_meter_id']);
            if (! $meter) {
                throw ValidationException::withMessages(["utility_openings.{$index}.utility_meter_id" => 'Đồng hồ phải đang hoạt động và thuộc dịch vụ áp dụng cho phòng này.']);
            }
            $contract->utilityOpenings()->create([
                'utility_meter_id' => $meter->id, 'service_id' => $meter->service_id, 'service_name' => $meter->service->name,
                'unit' => $meter->service->unit, 'opening_reading' => $opening['opening_reading'], 'reading_date' => $opening['reading_date'],
            ]);
        }
    }

    private function activate(Contract $contract, Room $room, BoardingHouse $house, ?array $representative): void
    {
        $errors = [];
        foreach (['start_date', 'monthly_rent', 'lessor_representative_user_id'] as $key) {
            if ($contract->{$key} === null) {
                $errors[$key] = match ($key) {
                    'start_date' => 'Vui lòng nhập ngày bắt đầu.',
                    'monthly_rent' => 'Vui lòng thiết lập giá thuê thỏa thuận trước khi kích hoạt hợp đồng.',
                    default => 'Vui lòng chọn người đại diện Bên A trước khi kích hoạt hợp đồng.',
                };
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        if (! $representative) {
            throw ValidationException::withMessages(['lessor_representative_user_id' => 'Vui lòng chọn người đại diện hợp lệ.']);
        }
        if ($contract->invoice_issue_day_from && $contract->invoice_issue_day_to && $contract->invoice_issue_day_from > $contract->invoice_issue_day_to) {
            throw ValidationException::withMessages(['invoice_issue_day_to' => 'Ngày cuối gửi hóa đơn phải từ ngày bắt đầu trở đi.']);
        }
        $overlaps = $room->contracts()->whereKeyNot($contract->id)->where('status', 'ACTIVE')
            ->whereDate('start_date', '<=', $contract->end_date?->format('Y-m-d') ?? '9999-12-31')
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $contract->start_date->format('Y-m-d')))->exists();
        if ($overlaps) {
            throw ValidationException::withMessages(['start_date' => 'Thời hạn hợp đồng trùng với hợp đồng đang hiệu lực của phòng. Vui lòng kiểm tra ngày bắt đầu và kết thúc.']);
        }
        // Re-read selected prices at activation, then freeze them with the signing evidence.
        $this->saveServices($contract, $room, $contract->agreedServices()->pluck('service_id')->all());
        $this->saveOpenings($contract, $room, $contract->utilityOpenings()->get()->map(fn ($opening) => [
            'utility_meter_id' => $opening->utility_meter_id, 'opening_reading' => $opening->opening_reading,
            'reading_date' => $opening->reading_date->format('Y-m-d'),
        ])->all());
        $ids = [(int) $contract->tenant_id, ...($contract->occupant_tenant_ids ?? [])];
        $unrelated = $room->roomTenants()->where('status', 'ACTIVE')->whereNotIn('tenant_id', $ids)->count();
        if ($room->max_tenants !== null && count($ids) + $unrelated > $room->max_tenants) {
            throw ValidationException::withMessages(['occupant_tenant_ids' => 'Số người đăng ký vượt quá số người tối đa của phòng.']);
        }
        foreach ($ids as $tenantId) {
            $membership = $room->roomTenants()->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->first();
            if ($membership && $membership->contract_id !== null && (int) $membership->contract_id !== (int) $contract->id) {
                $previous = $membership->contract;
                if (! $previous?->end_date || $previous->end_date >= $contract->start_date) {
                    throw ValidationException::withMessages(['occupant_tenant_ids' => 'Người ở đã thuộc một hợp đồng khác đang hiệu lực.']);
                }
                $membership->update(['status' => 'MOVED_OUT', 'move_out_date' => $previous->end_date->format('Y-m-d')]);
                $membership = null;
            }
            if (! $membership) {
                $membership = new RoomTenant(['room_id' => $room->id, 'tenant_id' => $tenantId, 'move_in_date' => $contract->start_date->format('Y-m-d')]);
            }
            if ($tenantId === (int) $contract->tenant_id && $room->roomTenants()->where('status', 'ACTIVE')->where('is_primary', true)->where('tenant_id', '!=', $tenantId)->exists()) {
                throw ValidationException::withMessages(['tenant_id' => 'Phòng đã có người đứng tên đang ở. Vui lòng xác nhận lịch sử chuyển đi trước.']);
            }
            $membership->fill(['contract_id' => $contract->id, 'status' => 'ACTIVE', 'move_out_date' => null, 'is_primary' => $tenantId === (int) $contract->tenant_id])->save();
        }
        $tenant = Tenant::query()->findOrFail($contract->tenant_id);
        $occupants = Tenant::query()->whereIn('id', $ids)->get()->map(fn (Tenant $person) => ['tenant_id' => $person->id, 'is_primary' => (int) $person->id === (int) $contract->tenant_id, ...$this->person($person)])->all();
        $contract->status = 'ACTIVE';
        $contract->finalized_at = now();
        $contract->signing_snapshot = [
            'schema_version' => 1, 'finalized_at' => now()->toIso8601String(),
            'lessor' => $representative, 'tenant' => $this->person($tenant),
            'contract' => $contract->only(['contract_code', 'start_date', 'end_date', 'signed_date', 'monthly_rent', 'deposit_amount', 'invoice_issue_day_from', 'invoice_issue_day_to', 'payment_due_day']),
            'room' => ['room_id' => $room->id, 'room_code' => $room->room_code, 'boarding_house_name' => $house->name, 'address' => $house->address, 'max_tenants' => $room->max_tenants],
            'services' => $contract->agreedServices()->get()->toArray(), 'occupants' => $occupants,
            'utility_openings' => $contract->utilityOpenings()->get()->toArray(),
        ];
        // Carbon instances inside JSON must not turn business DATE fields into UTC ISO.
        foreach (['start_date', 'end_date', 'signed_date'] as $key) {
            $snapshot = $contract->signing_snapshot;
            $snapshot['contract'][$key] = $contract->{$key}?->format('Y-m-d');
            $contract->signing_snapshot = $snapshot;
        }
        $contract->save();
    }

    private function person(Tenant $tenant): array
    {
        return [...$tenant->only(['full_name', 'permanent_address', 'gender', 'identity_number', 'identity_issue_place', 'phone', 'email']),
            'date_of_birth' => $tenant->date_of_birth?->format('Y-m-d'), 'identity_issue_date' => $tenant->identity_issue_date?->format('Y-m-d')];
    }

    private function transition(Contract $contract, string $target, ?string $moveOutDate): void
    {
        if ($target === $contract->status) {
            return;
        }
        if ($contract->status !== 'ACTIVE' || ! in_array($target, ['EXPIRED', 'TERMINATED'], true)) {
            throw ValidationException::withMessages(['status' => 'Không thể chuyển trạng thái hợp đồng theo yêu cầu.']);
        }
        if ($target === 'EXPIRED' && (! $contract->end_date || $contract->end_date->format('Y-m-d') > now()->toDateString())) {
            throw ValidationException::withMessages(['status' => 'Chỉ đánh dấu hết hạn khi đã đến ngày kết thúc hợp đồng.']);
        }
        if (! $moveOutDate) {
            throw ValidationException::withMessages(['move_out_date' => 'Vui lòng xác nhận ngày chuyển đi để kết thúc hợp đồng và lưu lịch sử người ở.']);
        }
        $memberships = $contract->occupants()->where('status', 'ACTIVE')->get();
        foreach ($memberships as $membership) {
            if ($moveOutDate < $membership->move_in_date->format('Y-m-d')) {
                throw ValidationException::withMessages(['move_out_date' => 'Ngày chuyển đi không được trước ngày vào ở.']);
            }
        }
        $contract->occupants()->where('status', 'ACTIVE')->update(['status' => 'MOVED_OUT', 'move_out_date' => $moveOutDate]);
        $contract->status = $target;
    }

    public function detail(User $user, int $id): array
    {
        $contract = $this->find($user, $id)->load(['room.boardingHouse', 'tenant', 'occupants.tenant', 'agreedServices', 'utilityOpenings', 'residenceDeclarations:id,contract_id,status']);

        return [...$contract->toArray(), 'is_legacy' => $contract->status !== 'DRAFT' && $contract->signing_snapshot === null,
            'operational_role' => $this->access->role($user, $contract->room->boardingHouse),
            'active_occupants_count' => $contract->occupants->where('status', 'ACTIVE')->count()];
    }

    public function remove(User $user, int $id): void
    {
        DB::transaction(function () use ($user, $id): void {
            $record = $this->find($user, $id);
            $this->access->house($user, $record->room->boarding_house_id, true);
            $record->refresh();
            if ($record->status !== 'DRAFT' || $record->finalized_at !== null || $record->invoices()->exists()) {
                throw ValidationException::withMessages(['status' => 'Chỉ được xóa hợp đồng nháp chưa có hóa đơn.']);
            }
            $record->delete();
        });
    }

    public function moveOut(User $user, int $id, int $occupantId, string $date): void
    {
        DB::transaction(function () use ($user, $id, $occupantId, $date): void {
            $contract = $this->find($user, $id);
            $this->access->house($user, $contract->room->boarding_house_id, true);
            $membership = $contract->occupants()->lockForUpdate()->findOrFail($occupantId);
            if ($membership->status !== 'ACTIVE' || $date < $membership->move_in_date->format('Y-m-d')) {
                throw ValidationException::withMessages(['move_out_date' => 'Ngày chuyển đi không hợp lệ hoặc người ở đã chuyển đi.']);
            }
            $membership->update(['move_out_date' => $date, 'status' => 'MOVED_OUT']);
        });
    }

    public function changeRoom(User $user, int $id, int $roomId): void
    {
        DB::transaction(function () use ($user, $id, $roomId): void {
            $contract = $this->find($user, $id);
            $originalRoomId = (int) $contract->room_id;
            $original = $this->access->room($user, $originalRoomId);
            $target = $this->access->room($user, $roomId);
            // Lock in a stable order when moving between two assigned houses.
            $houses = [];
            $houseIds = array_unique([$original->boarding_house_id, $target->boarding_house_id]);
            sort($houseIds);
            foreach ($houseIds as $houseId) {
                $houses[$houseId] = $this->access->house($user, $houseId, true);
            }
            Room::query()->whereIn('id', [$originalRoomId, $roomId])->orderBy('id')->lockForUpdate()->get();
            $contract->refresh();
            if ((int) $contract->room_id !== $originalRoomId || $contract->status !== 'DRAFT' || $contract->finalized_at !== null
                || $contract->invoices()->exists() || $contract->residenceDeclarations()->exists() || $contract->occupants()->exists()) {
                throw ValidationException::withMessages(['room_id' => 'Chỉ đổi phòng cho hợp đồng nháp chưa liên kết hóa đơn, cư trú hoặc lịch sử người ở.']);
            }
            if ((int) $houses[$original->boarding_house_id]->landlord_id !== (int) $houses[$target->boarding_house_id]->landlord_id) {
                throw ValidationException::withMessages(['room_id' => 'Phòng mới phải thuộc cùng workspace với hợp đồng.']);
            }
            if ($originalRoomId === $roomId) {
                return;
            }
            $contract->room_id = $roomId;
            if (! collect($this->representatives($houses[$target->boarding_house_id]))->contains('user_id', $contract->lessor_representative_user_id)) {
                $contract->lessor_representative_user_id = null;
            }
            // Preserve agreed rent/schedule; only room-specific drafts are refreshed.
            $contract->save();
            $this->saveServices($contract, $target, $this->eligibleServices($target)->pluck('id')->all());
            $this->saveOpenings($contract, $target, $this->context($user, $roomId)['utility_openings']->all());
        }, 3);
    }

    public function scheduleDate(string $month, ?int $day): ?string
    {
        if ($day === null) {
            return null;
        }
        $date = Carbon::parse($month)->startOfMonth();

        return $date->day(min($day, $date->daysInMonth))->format('Y-m-d');
    }
}
