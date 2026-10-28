<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TenantLookupRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'boarding_house_id' => ['nullable', 'integer', 'min:1'],
            'room_id' => ['nullable', 'integer', 'min:1'],
            'context_room_id' => ['nullable', 'integer', 'min:1'],
            'scope' => ['nullable', 'in:WORKSPACE,ACTIVE_ROOM'],
            'occupancy_status' => ['nullable', 'in:ACTIVE,UNASSIGNED,MOVED_OUT'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'ids' => ['sometimes', 'array', 'max:100'],
            'ids.*' => ['integer', 'min:1', 'distinct'],
            'exclude_ids' => ['sometimes', 'array', 'max:100'],
            'exclude_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }

    public function attributes(): array
    {
        return [
            'search' => 'từ khóa tìm kiếm',
            'boarding_house_id' => 'nhà trọ',
            'room_id' => 'phòng',
            'context_room_id' => 'phòng đang thao tác',
            'scope' => 'phạm vi chọn người thuê',
            'occupancy_status' => 'trạng thái cư trú',
            'page' => 'trang',
            'per_page' => 'số kết quả mỗi trang',
            'ids' => 'người thuê đã chọn',
            'ids.*' => 'người thuê đã chọn',
            'exclude_ids' => 'người thuê loại trừ',
            'exclude_ids.*' => 'người thuê loại trừ',
        ];
    }
}
