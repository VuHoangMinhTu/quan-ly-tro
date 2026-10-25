<?php

namespace App\Http\Requests\BoardingHouse;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBoardingHouseWithRoomsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('rooms'))) {
            $this->merge(['rooms' => array_map(function ($room) {
                if (is_array($room) && is_string($room['room_code'] ?? null)) {
                    $room['room_code'] = trim($room['room_code']);
                }
                if (is_array($room) && ($room['room_type'] ?? 'PRIVATE_ROOM') === 'DORMITORY') {
                    $room['private_room_type'] = null;
                }

                return $room;
            }, $this->input('rooms'))]);
        }
    }

    public function rules(): array
    {
        return [
            'boarding_house' => ['required', 'array:name,address,ward,description'],
            'boarding_house.name' => ['required', 'string', 'max:255'],
            'boarding_house.address' => ['required', 'string', 'max:500'],
            'boarding_house.ward' => ['nullable', 'string', 'max:255'],
            'boarding_house.description' => ['nullable', 'string'],
            'rooms' => ['required', 'array', 'list', 'min:1', 'max:200'],
            'rooms.*' => ['required', 'array:room_code,room_type,private_room_type,area,monthly_rent,max_tenants'],
            'rooms.*.room_code' => ['required', 'string', 'max:50', 'distinct:ignore_case'],
            'rooms.*.room_type' => ['sometimes', Rule::in(['PRIVATE_ROOM', 'DORMITORY'])],
            'rooms.*.private_room_type' => ['nullable', Rule::in(['STUDIO', 'LOFT', 'NO_LOFT', 'CLOSED_ROOM'])],
            'rooms.*.area' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'rooms.*.monthly_rent' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'rooms.*.max_tenants' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
        ];
    }

    public function messages(): array
    {
        return [
            'rooms.required' => 'Vui lòng thêm ít nhất một phòng.',
            'rooms.min' => 'Vui lòng thêm ít nhất một phòng.',
            'rooms.max' => 'Mỗi lần chỉ được tạo tối đa 200 phòng.',
            'rooms.*.room_code.required' => 'Vui lòng nhập mã phòng.',
            'rooms.*.room_code.distinct' => 'Mã phòng bị trùng trong danh sách.',
            'rooms.*.room_type.in' => 'Loại phòng không hợp lệ.',
            'rooms.*.private_room_type.in' => 'Phân loại phòng không hợp lệ.',
            'rooms.*.area.numeric' => 'Diện tích phải là số.',
            'rooms.*.area.min' => 'Diện tích phải từ 0 trở lên.',
            'rooms.*.monthly_rent.numeric' => 'Giá thuê phải là số.',
            'rooms.*.monthly_rent.min' => 'Giá thuê phải từ 0 trở lên.',
            'rooms.*.max_tenants.integer' => 'Số người tối đa phải là số nguyên.',
            'rooms.*.max_tenants.min' => 'Số người tối đa phải ít nhất là 1.',
        ];
    }
}
