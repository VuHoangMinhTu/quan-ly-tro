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
        return ['household_head_name' => 'họ tên chủ hộ', 'household_head_identity_number' => 'số định danh cá nhân của chủ hộ'];
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
            'household_head_identity_number' => ['nullable', 'string', 'max:30'],
        ];
    }
}
