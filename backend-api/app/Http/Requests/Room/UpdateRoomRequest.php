<?php

namespace App\Http\Requests\Room;

use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UpdateRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $roomId = $this->route('id');

        $boardingHouseId = Room::query()
            ->whereKey($roomId)
            ->whereHas('boardingHouse', function ($query) {
                $query->where(
                    'landlord_id',
                    $this->user()?->landlord?->id
                );
            })
            ->value('boarding_house_id');

        return [
            'room_code' => [
                'required',
                'string',
                'max:50',

                Rule::unique('rooms', 'room_code')
                    ->where(
                        'boarding_house_id',
                        $boardingHouseId ?? 0
                    )
                    ->ignore($roomId),
            ],

            'room_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'area' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'monthly_rent' => [
                'required',
                'numeric',
                'min:0',
            ],

            'max_tenants' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'status' => [
                'required',
                Rule::in([
                    'AVAILABLE',
                    'RENTED',
                    'RESERVED',
                    'MAINTENANCE',
                ]),
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'amenity_ids' => [
                'nullable',
                'array',
            ],

            'amenity_ids.*' => [
                'integer',
                'exists:amenities,id',
            ],
        ];
    }

    private function findOwnedRoom(Request $request, int $id): Room
    {
        return Room::query()
            ->whereKey($id)
            ->whereHas('boardingHouse', function ($query) use ($request) {
                $query->where(
                    'landlord_id',
                    $request->user()->landlord->id
                );
            })
            ->firstOrFail();
    }
}
