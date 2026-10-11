<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TenantIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('q'))) {
            $this->merge(['q' => trim($this->input('q'))]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'boarding_house_id' => ['nullable', 'integer', 'min:1'],
            'room_id' => ['nullable', 'integer', 'min:1'],
            'occupancy_status' => ['nullable', Rule::in(['ACTIVE', 'MOVED_OUT', 'UNASSIGNED'])],
            'role' => ['nullable', Rule::in(['PRIMARY', 'ROOMMATE'])],
            'contract_status' => ['nullable', Rule::in(['ACTIVE', 'EXPIRED', 'TERMINATED', 'NONE'])],
        ];
    }

    public function attributes(): array
    {
        return [
            'q' => 'từ khóa tìm kiếm',
            'occupancy_status' => 'trạng thái cư trú',
            'role' => 'vai trò',
            'contract_status' => 'trạng thái hợp đồng',
        ];
    }
}
