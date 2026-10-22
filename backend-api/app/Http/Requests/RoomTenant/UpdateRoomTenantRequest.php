<?php

namespace App\Http\Requests\RoomTenant;

use App\Http\Requests\Concerns\ValidatesRoomTenantMembership;
use App\Models\RoomTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateRoomTenantRequest extends FormRequest
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
            $roomTenant = $this->ownedRoomTenant();

            $this->validateRoomTenantMembership(
                $validator,
                $roomTenant?->room,
                $roomTenant?->id,
                $this->has('is_primary') ? $this->boolean('is_primary') : (bool) $roomTenant?->is_primary
            );
            if ($roomTenant?->room?->room_type === 'DORMITORY') {
                $bedId = $this->input('bed_id');
                if ($this->input('status') === 'ACTIVE' && ! $bedId) {
                    $validator->errors()->add('bed_id', 'Vui lòng chọn giường cho người ở KTX.');
                } elseif ($bedId) {
                    $bed = $roomTenant->room->beds()->find($bedId);
                    if (! $bed || $bed->status === 'MAINTENANCE' || ($this->input('status') === 'ACTIVE' && $bed->roomTenants()->where('status', 'ACTIVE')->whereKeyNot($roomTenant->id)->exists())) {
                        $validator->errors()->add('bed_id', 'Giường không hợp lệ, đang bảo trì hoặc đã có người ở.');
                    }
                }
            } elseif ($this->filled('bed_id')) {
                $validator->errors()->add('bed_id', 'Phòng trọ thường không phân giường.');
            }
        });
    }

    private function ownedRoomTenant(): ?RoomTenant
    {
        return RoomTenant::query()
            ->whereKey($this->route('id'))
            ->whereHas('room.boardingHouse', fn ($query) => $query->where('landlord_id', $this->user()?->landlord?->id))
            ->first();
    }
}
