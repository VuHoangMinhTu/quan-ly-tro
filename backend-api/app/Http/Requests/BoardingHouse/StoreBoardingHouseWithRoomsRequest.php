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
        if (is_array($this->input('floors'))) {
            $this->merge(['floors' => array_map(function ($floor) {
                if (is_array($floor) && is_string($floor['name'] ?? null)) {
                    $floor['name'] = trim($floor['name']);
                }

                return $floor;
            }, $this->input('floors'))]);
        }
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
            'floors' => ['sometimes', 'array', 'list', 'max:200'],
            'floors.*' => ['required', 'array:client_key,name,sort_order'],
            'floors.*.client_key' => ['required', 'string', 'max:100', 'distinct'],
            'floors.*.name' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'floors.*.sort_order' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'rooms' => ['required', 'array', 'list', 'min:1', 'max:200'],
            'rooms.*' => ['required', 'array:room_code,room_type,private_room_type,area,monthly_rent,max_tenants,floor_client_key'],
            'rooms.*.floor_client_key' => ['nullable', 'string', Rule::in(collect($this->input('floors', []))->pluck('client_key')->filter(fn ($key) => is_string($key))->all())],
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
            'floors.max' => 'Mỗi lần chỉ được tạo tối đa 200 tầng.',
            'floors.*.client_key.distinct' => 'Khóa tầng tạm bị trùng.',
            'floors.*.name.required' => 'Vui lòng nhập tên tầng.',
            'floors.*.name.distinct' => 'Tên tầng bị trùng trong danh sách.',
            'floors.*.name.max' => 'Tên tầng tối đa 100 ký tự.',
            'floors.*.sort_order.min' => 'Thứ tự tầng phải từ 0 trở lên.',
            'rooms.*.floor_client_key.in' => 'Vui lòng chọn tầng trong danh sách tầng vừa thiết lập.',
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
