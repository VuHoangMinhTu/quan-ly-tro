<?php

namespace App\Services;

use App\Models\ResidenceDeclaration;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResidenceDeclarationService
{
    public function __construct(private BoardingHouseAccessService $access) {}

    public function find(User $user, int $id, bool $lock = false): ResidenceDeclaration
    {
        return ResidenceDeclaration::query()->whereIn('boarding_house_id', $this->access->houses($user)->select('boarding_houses.id'))
            ->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($id);
    }

    public function context(User $user, int $roomId): array
    {
        $room = $this->access->room($user, $roomId);
        $room->load('boardingHouse.residenceProfile');

        return [
            'room' => $room, 'operational_role' => $this->access->role($user, $room->boardingHouse),
            'occupants' => $room->roomTenants()->where('status', 'ACTIVE')
                ->whereHas('tenant', fn ($query) => $query->where('landlord_id', $room->boardingHouse->landlord_id))->with('tenant')->get(),
            'contracts' => $room->contracts()->with('tenant')->get(),
        ];
    }

    public function save(User $user, array $data, ?int $id = null): ResidenceDeclaration
    {
        return DB::transaction(function () use ($user, $data, $id): ResidenceDeclaration {
            $record = $id === null ? new ResidenceDeclaration : $this->find($user, $id, true);
            if ($record->snapshot !== null || ! in_array($record->status ?? 'DRAFT', ['DRAFT', 'PENDING'], true)) {
                throw ValidationException::withMessages(['status' => 'Hồ sơ đã chốt không được sửa nội dung. Hãy tạo hồ sơ mới nếu cần điều chỉnh.']);
            }
            $room = $this->access->room($user, $data['room_id']);
            $house = $this->access->house($user, $room->boarding_house_id, true);
            if ($record->exists && (string) $record->room_id !== (string) $room->id) {
                throw ValidationException::withMessages(['room_id' => 'Không thể chuyển hồ sơ sang phòng khác.']);
            }
            if ($record->exists) {
                $data = [...$record->only(['contract_id', 'declarant_tenant_id', 'authority_name', 'relationship_to_household_head', 'request_content']), ...$data];
            }
            $this->validatePeople($room, $data);
            if (! empty($data['contract_id']) && ! $room->contracts()->whereKey($data['contract_id'])->exists()) {
                throw ValidationException::withMessages(['contract_id' => 'Hợp đồng phải thuộc phòng này.']);
            }
            $record->fill(Arr::only($data, ['contract_id', 'declarant_tenant_id', 'authority_name', 'relationship_to_household_head', 'request_content']));
            $record->landlord_id = $house->landlord_id;
            $record->boarding_house_id = $house->id;
            $record->room_id = $room->id;
            if (! $record->exists) {
                $record->status = 'DRAFT';
            }
            $record->save();
            if (array_key_exists('members', $data)) {
                $record->members()->delete();
                $record->members()->createMany(array_map(fn (array $member) => Arr::only($member, ['tenant_id', 'relationship_to_household_head']), $data['members']));
            }

            return $record;
        }, 3);
    }

    private function validatePeople(Room $room, array $data): void
    {
        $activeIds = $room->roomTenants()->where('status', 'ACTIVE')
            ->whereHas('tenant', fn ($query) => $query->where('landlord_id', $room->boardingHouse->landlord_id))->pluck('tenant_id')->map(fn ($id) => (int) $id)->all();
        if (! empty($data['declarant_tenant_id']) && ! in_array((int) $data['declarant_tenant_id'], $activeIds, true)) {
            throw ValidationException::withMessages(['declarant_tenant_id' => 'Người kê khai phải đang ở trong phòng này.']);
        }
        foreach ($data['members'] ?? [] as $index => $member) {
            if (! in_array((int) $member['tenant_id'], $activeIds, true) || (int) $member['tenant_id'] === (int) ($data['declarant_tenant_id'] ?? 0)) {
                throw ValidationException::withMessages(["members.{$index}.tenant_id" => 'Thành viên phải đang ở trong phòng và không trùng người kê khai.']);
            }
        }
    }

    private function person(?Tenant $tenant): array
    {
        return [
            'tenant_id' => $tenant?->id, 'full_name' => $tenant?->full_name,
            'date_of_birth' => $tenant?->date_of_birth?->format('Y-m-d'), 'gender' => $tenant?->gender,
            'identity_number' => $tenant?->identity_number, 'phone' => $tenant?->phone, 'email' => $tenant?->email,
        ];
    }

    public function preview(ResidenceDeclaration $record): array
    {
        if ($record->snapshot !== null) {
            $ct01 = $record->snapshot;
        } else {
            $record->load(['declarant', 'members.tenant', 'room', 'boardingHouse.residenceProfile']);
            $profile = $record->boardingHouse->residenceProfile;
            $ct01 = [
                'schema_version' => 1, 'captured_at' => now()->toIso8601String(),
                'authority_name' => $record->authority_name, 'request_content' => $record->request_content,
                'relationship_to_household_head' => $record->relationship_to_household_head,
                'declarant' => $this->person($record->declarant),
                'household_head' => ['name' => $profile?->household_head_name, 'identity_number' => $profile?->household_head_identity_number],
                'residence' => ['boarding_house_name' => $record->boardingHouse->name, 'address' => $record->boardingHouse->address, 'room_code' => $record->room->room_code],
                'members' => $record->members->map(fn ($member) => [...$this->person($member->tenant), 'relationship_to_household_head' => $member->relationship_to_household_head])->all(),
            ];
        }

        return ['ct01' => $ct01, 'missing_fields' => $this->missingFields($ct01), 'is_frozen' => $record->snapshot !== null];
    }

    private function missingFields(array $ct01): array
    {
        $missing = [];
        foreach (['authority_name' => 'Chưa nhập cơ quan đăng ký cư trú.', 'request_content' => 'Chưa nhập nội dung đề nghị.', 'relationship_to_household_head' => 'Chưa nhập quan hệ của người kê khai với chủ hộ.', 'household_head.name' => 'Nhà trọ chưa có thông tin chủ hộ.', 'household_head.identity_number' => 'Số định danh cá nhân của chủ hộ chưa được cập nhật.'] as $field => $message) {
            if (blank(Arr::get($ct01, $field))) {
                $missing[] = ['field' => $field, 'message' => $message, 'target' => str_starts_with($field, 'household_head') ? 'profile' : 'declaration'];
            }
        }
        foreach (['declarant' => $ct01['declarant'], ...array_combine(array_map(fn ($i) => 'members.'.$i, array_keys($ct01['members'])), $ct01['members'])] as $prefix => $person) {
            foreach (['full_name' => 'Họ tên', 'date_of_birth' => 'Ngày sinh', 'gender' => 'Giới tính', 'identity_number' => 'Số định danh cá nhân'] as $field => $label) {
                if (blank($person[$field] ?? null)) {
                    $missing[] = ['field' => $prefix.'.'.$field, 'message' => $label.' của '.($person['full_name'] ?: 'người kê khai').' chưa được cập nhật.', 'target' => 'tenant', 'tenant_id' => $person['tenant_id']];
                }
            }
            if ($prefix !== 'declarant' && blank($person['relationship_to_household_head'] ?? null)) {
                $missing[] = ['field' => $prefix.'.relationship_to_household_head', 'message' => 'Chưa nhập quan hệ với chủ hộ của '.$person['full_name'].'.', 'target' => 'declaration'];
            }
        }

        return $missing;
    }

    public function export(ResidenceDeclaration $record): array
    {
        if ($record->snapshot === null) {
            $this->validatePeople($record->room, ['declarant_tenant_id' => $record->declarant_tenant_id, 'members' => $record->members->toArray()]);
        }
        $preview = $this->preview($record);
        if ($preview['missing_fields'] !== []) {
            $errors = [];
            foreach ($preview['missing_fields'] as $missing) {
                $errors[$missing['field']] = $missing['message'];
            }
            throw ValidationException::withMessages($errors);
        }

        return $preview;
    }

    public function changeStatus(User $user, int $id, array $data): void
    {
        DB::transaction(function () use ($user, $id, $data): void {
            $record = $this->find($user, $id, true);
            $this->access->house($user, $record->boarding_house_id, true);
            $transitions = ['DRAFT' => ['PENDING', 'SUBMITTED'], 'PENDING' => ['DRAFT', 'SUBMITTED', 'REJECTED'], 'SUBMITTED' => ['REGISTERED', 'REJECTED'], 'REGISTERED' => ['EXPIRED'], 'REJECTED' => [], 'EXPIRED' => []];
            if (! in_array($data['status'], $transitions[$record->status], true)) {
                throw ValidationException::withMessages(['status' => 'Không thể chuyển sang trạng thái này từ trạng thái hiện tại.']);
            }
            if ($record->snapshot === null && in_array($data['status'], ['SUBMITTED', 'REGISTERED'], true)) {
                $this->validatePeople($record->room, ['declarant_tenant_id' => $record->declarant_tenant_id, 'members' => $record->members->toArray()]);
                $record->snapshot = $this->export($record)['ct01'];
                $record->submitted_at ??= now();
            }
            if ($data['status'] === 'REGISTERED') {
                $record->registered_at = now();
            }
            $record->status = $data['status'];
            if (array_key_exists('expires_at', $data)) {
                $record->expires_at = $data['expires_at'];
            }
            $record->save();
        }, 3);
    }
}
