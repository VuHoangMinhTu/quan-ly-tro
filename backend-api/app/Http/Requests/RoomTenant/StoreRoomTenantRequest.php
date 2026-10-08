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
            'is_primary' => ['boolean'],
            'move_in_date' => ['required', 'date'],
            'move_out_date' => ['nullable', 'date', 'after_or_equal:move_in_date'],
            'status' => ['required', Rule::in(['ACTIVE', 'MOVED_OUT'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validateRoomTenantMembership(
            $validator,
            $this->ownedRoom(),
            isPrimary: $this->boolean('is_primary')
        ));
    }

    private function ownedRoom(): ?Room
    {
        return Room::query()
            ->whereKey($this->route('roomId'))
            ->whereHas('boardingHouse', fn ($query) => $query->where('landlord_id', $this->user()?->landlord?->id))
            ->first();
    }
}
