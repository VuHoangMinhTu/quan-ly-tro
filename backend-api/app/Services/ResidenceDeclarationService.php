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
            'suggested_declaration' => ['authority_name' => $room->boardingHouse->residenceProfile?->residence_authority_name,
                'request_content' => 'Đăng ký tạm trú', 'members' => []],
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
                $data = [...$record->only(['contract_id', 'declarant_tenant_id', 'authority_name', 'household_head_mode', 'household_head_name', 'household_head_identity_number', 'relationship_to_household_head', 'request_content', 'stay_start_date', 'stay_end_date', 'household_head_opinion', 'legal_owner_opinion', 'guardian_opinion', 'household_head_confirmation_method', 'legal_owner_confirmation_method', 'guardian_confirmation_method']), ...$data];
            } else {
                $house->load('residenceProfile');
                $data['authority_name'] = $data['authority_name'] ?? $house->residenceProfile?->residence_authority_name;
                $data['request_content'] = $data['request_content'] ?? 'Đăng ký tạm trú';
                $data['guardian_confirmation_method'] ??= 'NOT_APPLICABLE';
            }
            $this->validatePeople($room, $data);
            if (($data['household_head_mode'] ?? null) === 'DECLARANT') {
                $data['household_head_name'] = null;
                $data['household_head_identity_number'] = null;
                $data['relationship_to_household_head'] = 'Chủ hộ';
                $data['household_head_confirmation_method'] = null;
            } elseif (($data['household_head_mode'] ?? null) !== 'OTHER_PERSON') {
                $data['household_head_name'] = null;
                $data['household_head_identity_number'] = null;
                $data['relationship_to_household_head'] = null;
            }
            if (! empty($data['contract_id'])) {
                $contract = $room->contracts()->find($data['contract_id']);
                if ($contract === null) {
                    throw ValidationException::withMessages(['contract_id' => 'Hợp đồng phải thuộc phòng này.']);
                }
                if (! $record->exists) {
                    $data['stay_start_date'] ??= $contract->start_date?->format('Y-m-d');
                    $data['stay_end_date'] ??= $contract->end_date?->format('Y-m-d');
                }
            }
            $record->fill(Arr::only($data, ['contract_id', 'declarant_tenant_id', 'authority_name', 'household_head_mode', 'household_head_name', 'household_head_identity_number', 'relationship_to_household_head', 'request_content', 'stay_start_date', 'stay_end_date', 'household_head_opinion', 'legal_owner_opinion', 'guardian_opinion', 'household_head_confirmation_method', 'legal_owner_confirmation_method', 'guardian_confirmation_method']));
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
            'permanent_address' => $tenant?->permanent_address,
        ];
    }

    public function preview(ResidenceDeclaration $record): array
    {
        if ($record->snapshot !== null) {
            $ct01 = $record->snapshot;
        } else {
            $record->load(['declarant', 'members.tenant', 'room', 'boardingHouse.residenceProfile', 'contract']);
            $profile = $record->boardingHouse->residenceProfile;
            $ct01 = [
                'schema_version' => 2, 'captured_at' => now()->toIso8601String(),
                'household_head_mode' => $record->household_head_mode,
                'authority_name' => $record->authority_name, 'request_content' => $record->request_content,
                'relationship_to_household_head' => $record->household_head_mode === 'DECLARANT' ? 'Chủ hộ' : $record->relationship_to_household_head,
                'opinions' => ['household_head' => $record->household_head_opinion, 'legal_owner' => $record->legal_owner_opinion, 'guardian' => $record->guardian_opinion],
                'confirmation_methods' => ['household_head' => $record->household_head_confirmation_method, 'legal_owner' => $record->legal_owner_confirmation_method, 'guardian' => $record->guardian_confirmation_method],
                'declarant' => $this->person($record->declarant),
                'household_head' => ['name' => match ($record->household_head_mode) {
                    'DECLARANT' => $record->declarant?->full_name,
                    'OTHER_PERSON' => $record->household_head_name,
                    default => null,
                }, 'identity_number' => match ($record->household_head_mode) {
                    'DECLARANT' => $record->declarant?->identity_number,
                    'OTHER_PERSON' => $record->household_head_identity_number,
                    default => null,
                }],
                'residence' => ['boarding_house_name' => $record->boardingHouse->name, 'address' => $record->boardingHouse->address, 'room_code' => $record->room->room_code],
                'members' => $record->members->map(fn ($member) => [...$this->person($member->tenant), 'relationship_to_household_head' => $member->relationship_to_household_head])->all(),
            ];
            $ct01['stay_consent'] = $this->stayConsent($record, $ct01);
        }

        $consent = $ct01['stay_consent'] ?? null;

        return ['ct01' => $ct01, 'stay_consent' => $consent,
            'stay_consent_missing_fields' => $consent === null ? ['document' => 'Hồ sơ đã chốt trước khi có văn bản ở nhờ.'] : $this->missingStayConsent($consent),
            'missing_fields' => $this->missingFields($ct01), 'is_frozen' => $record->snapshot !== null];
    }

    private function stayConsent(ResidenceDeclaration $record, array $ct01): array
    {
        $profile = $record->boardingHouse->residenceProfile;
        $declarant = $ct01['declarant'];

        return [
            'type' => 'RESIDENCE_STAY_CONSENT', 'captured_at' => now()->toDateString(),
            'location' => $record->boardingHouse->address,
            'host' => [
                'full_name' => $profile?->legal_owner_name,
                'birth_year' => $profile?->legal_owner_birth_year,
                'permanent_address' => $profile?->legal_owner_permanent_address,
                'identity_number' => $profile?->legal_owner_identity_number,
                'phone' => $profile?->legal_owner_phone,
            ],
            'guest' => ['full_name' => $declarant['full_name'], 'birth_year' => $declarant['date_of_birth'] ? substr($declarant['date_of_birth'], 0, 4) : null,
                'permanent_address' => $declarant['permanent_address'], 'identity_number' => $declarant['identity_number'], 'phone' => $declarant['phone']],
            'stay_address' => $record->boardingHouse->address, 'room_code' => $record->room->room_code,
            'purpose' => 'để ở', 'companions' => $ct01['members'], 'occupant_count' => 1 + count($ct01['members']),
            'start_date' => $record->stay_start_date?->format('Y-m-d') ?? $record->contract?->start_date?->format('Y-m-d'),
            'end_date' => $record->stay_end_date?->format('Y-m-d') ?? $record->contract?->end_date?->format('Y-m-d'),
        ];
    }

    public function exportStayConsent(ResidenceDeclaration $record): array
    {
        $preview = $this->preview($record);
        $consent = $preview['stay_consent'];
        if ($consent === null) {
            throw ValidationException::withMessages(['document' => 'Hồ sơ đã chốt trước khi có văn bản ở nhờ; không thể tự tạo văn bản từ dữ liệu hiện tại.']);
        }
        $missing = $preview['stay_consent_missing_fields'];
        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }

        return ['document' => $consent, 'is_frozen' => $preview['is_frozen']];
    }

    private function missingStayConsent(array $consent): array
    {
        $missing = [];
        foreach (['host.full_name', 'host.birth_year', 'host.permanent_address', 'host.identity_number', 'host.phone',
            'guest.full_name', 'guest.birth_year', 'guest.permanent_address', 'guest.identity_number', 'guest.phone',
            'stay_address', 'start_date', 'end_date'] as $field) {
            if (blank(Arr::get($consent, $field))) {
                $missing[$field] = 'Vui lòng bổ sung '.str_replace(['host.', 'guest.', '_'], ['Bên A: ', 'Bên B: ', ' '], $field).' trước khi in văn bản.';
            }
        }

        return $missing;
    }

    private function missingFields(array $ct01): array
    {
        $missing = [];
        if (blank($ct01['household_head_mode'] ?? null)) {
            $missing[] = ['field' => 'household_head_mode', 'message' => 'Vui lòng xác nhận ai là chủ hộ của hồ sơ cư trú.', 'target' => 'declaration'];
        }
        foreach (['authority_name' => 'Chưa nhập cơ quan đăng ký cư trú.', 'request_content' => 'Chưa nhập nội dung đề nghị.', 'relationship_to_household_head' => 'Chưa nhập quan hệ của người kê khai với chủ hộ.', 'household_head.name' => 'Chưa nhập họ tên chủ hộ.', 'household_head.identity_number' => 'Chưa nhập số định danh cá nhân của chủ hộ.'] as $field => $message) {
            if (blank(Arr::get($ct01, $field))) {
                $missing[] = ['field' => $field, 'message' => $message, 'target' => 'declaration'];
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
