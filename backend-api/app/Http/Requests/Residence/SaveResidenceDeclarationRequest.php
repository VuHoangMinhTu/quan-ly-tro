<?php

namespace App\Http\Requests\Residence;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveResidenceDeclarationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function attributes(): array
    {
        return ['room_id' => 'phòng', 'contract_id' => 'hợp đồng', 'declarant_tenant_id' => 'người kê khai', 'authority_name' => 'cơ quan đăng ký cư trú', 'relationship_to_household_head' => 'quan hệ với chủ hộ', 'request_content' => 'nội dung đề nghị', 'stay_start_date' => 'ngày bắt đầu ở nhờ', 'stay_end_date' => 'ngày kết thúc ở nhờ', 'members' => 'thành viên', 'members.*.tenant_id' => 'thành viên', 'members.*.relationship_to_household_head' => 'quan hệ với chủ hộ'];
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'room_id' => ['required', 'integer', 'min:1'],
            'contract_id' => ['nullable', 'integer', 'min:1'],
            'declarant_tenant_id' => ['nullable', 'integer', 'min:1'],
            'authority_name' => ['nullable', 'string', 'max:255'],
            'relationship_to_household_head' => ['nullable', 'string', 'max:255'],
            'request_content' => ['nullable', 'string', 'max:5000'],
            'stay_start_date' => ['nullable', 'date_format:Y-m-d'],
            'stay_end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:stay_start_date'],
            'household_head_opinion' => ['nullable', 'string', 'max:2000'],
            'legal_owner_opinion' => ['nullable', 'string', 'max:2000'],
            'guardian_opinion' => ['nullable', 'string', 'max:2000'],
            'members' => ['sometimes', 'array', 'max:100'],
            'members.*.tenant_id' => ['required', 'integer', 'min:1', 'distinct'],
            'members.*.relationship_to_household_head' => ['nullable', 'string', 'max:255'],
        ];
    }
}
