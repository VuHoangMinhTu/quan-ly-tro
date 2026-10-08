<?php

namespace App\Http\Requests\Room;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $boardingHouseId = $this->ownedBoardingHouseId();

        return [
            'room_code' => ['required', 'string', 'max:50', Rule::unique('rooms', 'room_code')->where('boarding_house_id', $boardingHouseId ?? 0)],
            'room_name' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'numeric', 'min:0'],
            'monthly_rent' => ['required', 'numeric', 'min:0'],
            'max_tenants' => ['nullable', 'integer', 'min:1'],
            'status' => ['required', Rule::in(['AVAILABLE', 'RENTED', 'RESERVED', 'MAINTENANCE'])],
            'description' => ['nullable', 'string'],
            'amenity_ids' => ['nullable', 'array'],
            'amenity_ids.*' => ['integer', 'exists:amenities,id'],
        ];
    }

    private function ownedBoardingHouseId(): ?int
    {
        return $this->user()?->landlord
            ?->boardingHouses()
            ->whereKey($this->route('boardingHouseId'))
            ->value('id');
    }
}
