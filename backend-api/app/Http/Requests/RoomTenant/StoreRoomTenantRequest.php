<?php

namespace App\Http\Requests\RoomTenant;

use App\Http\Requests\Concerns\ValidatesRoomTenantMembership;
use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRoomTenantRequest extends FormRequest
{
    use ValidatesRoomTenantMembership;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'bed_id' => ['nullable', 'integer', 'exists:beds,id'],
            'is_primary' => ['boolean'],
            'move_in_date' => ['required', 'date'],
            'move_out_date' => ['nullable', 'date', 'after_or_equal:move_in_date'],
            'status' => ['required', Rule::in(['ACTIVE', 'MOVED_OUT'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $room = $this->ownedRoom();
            $this->validateRoomTenantMembership($validator, $room, isPrimary: $this->boolean('is_primary'));
            $this->validateBedAssignment($validator, $room);
        });
    }

    private function validateBedAssignment(Validator $validator, ?Room $room): void
    {
        if (! $room) return;
        $bedId = $this->input('bed_id');
        if ($room->room_type !== 'DORMITORY') {
            if ($bedId !== null) $validator->errors()->add('bed_id', 'Phòng trọ thường không phân giường.');
            return;
        }
        if (! $bedId) {
            if ($this->input('status') === 'ACTIVE') $validator->errors()->add('bed_id', 'Vui lòng chọn giường cho người ở KTX.');
            return;
        }
        $bed = $room->beds()->find($bedId);
        if (! $bed || ($this->input('status') === 'ACTIVE' && $bed->status === 'MAINTENANCE')) { $validator->errors()->add('bed_id', 'Giường không hợp lệ hoặc đang bảo trì.'); return; }
        if ($this->input('status') === 'ACTIVE' && $bed->roomTenants()->where('status', 'ACTIVE')->exists()) $validator->errors()->add('bed_id', 'Giường này đã có người ở.');
    }

    private function ownedRoom(): ?Room
    {
        return Room::query()
            ->whereKey($this->route('roomId'))
            ->whereHas('boardingHouse', fn ($query) => $query->where('landlord_id', $this->user()?->landlord?->id))
            ->first();
    }
}
