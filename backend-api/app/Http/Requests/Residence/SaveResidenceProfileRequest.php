<?php

namespace App\Http\Requests\Residence;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveResidenceProfileRequest extends FormRequest
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
        return ['household_head_name' => 'họ tên chủ hộ', 'household_head_identity_number' => 'số định danh cá nhân của chủ hộ', 'residence_authority_name' => 'cơ quan tiếp nhận', 'document_place' => 'địa danh lập văn bản', 'legal_owner_name' => 'họ tên chủ sở hữu chỗ ở', 'legal_owner_birth_date' => 'ngày sinh chủ sở hữu', 'legal_owner_permanent_address' => 'địa chỉ thường trú chủ sở hữu', 'legal_owner_identity_number' => 'số định danh chủ sở hữu', 'legal_owner_phone' => 'số điện thoại chủ sở hữu'];
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'household_head_name' => ['nullable', 'string', 'max:255'],
            'household_head_identity_number' => ['nullable', 'string', 'regex:/^\d{12}$/'],
            'residence_authority_name' => ['nullable', 'string', 'max:255'],
            'document_place' => ['nullable', 'string', 'max:255'],
            'legal_owner_name' => ['nullable', 'string', 'max:255'],
            'legal_owner_birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'legal_owner_permanent_address' => ['nullable', 'string', 'max:500'],
            'legal_owner_identity_number' => ['nullable', 'string', 'regex:/^\d{12}$/'],
            'legal_owner_phone' => ['nullable', 'string', 'regex:/^(?:\+84|0)[35789]\d{8}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'household_head_identity_number.regex' => 'Số định danh cá nhân của chủ hộ phải gồm đúng 12 chữ số.',
            'legal_owner_identity_number.regex' => 'Số định danh cá nhân phải gồm đúng 12 chữ số.',
            'legal_owner_phone.regex' => 'Số điện thoại phải là số di động Việt Nam hợp lệ.',
        ];
    }
}
