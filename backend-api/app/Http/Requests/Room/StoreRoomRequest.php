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
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:4294967295'],
            'floor_id' => ['nullable', 'integer', Rule::exists('floors', 'id')->where('boarding_house_id', $boardingHouseId ?? 0)],
            'room_type' => ['sometimes', Rule::in(['PRIVATE_ROOM', 'DORMITORY'])],
            'area' => ['nullable', 'numeric', 'min:0'],
            'monthly_rent' => ['nullable', 'numeric', 'min:0'],
            'max_tenants' => ['nullable', 'integer', 'min:1'],
            'status' => ['required', Rule::in(['AVAILABLE', 'RENTED', 'RESERVED', 'MAINTENANCE'])],
            'description' => ['nullable', 'string'],
            'amenity_ids' => ['sometimes', 'nullable', 'array'],
            'amenity_ids.*' => ['integer', 'exists:amenities,id'],
            'beds' => ['sometimes', 'array', 'max:100'],
            'beds.*.bed_code' => ['required_with:beds', 'string', 'max:50', 'distinct:ignore_case'],
            'beds.*.monthly_rent' => ['nullable', 'numeric', 'min:0'],
            'beds.*.status' => ['sometimes', Rule::in(['AVAILABLE', 'MAINTENANCE'])],
            'beds.*.note' => ['nullable', 'string'],
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
