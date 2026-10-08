<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'gender' => ['nullable', Rule::in(['MALE', 'FEMALE', 'OTHER'])],
            'identity_number' => ['nullable', 'string', 'max:30'],
            'identity_issue_place' => ['nullable', 'string', 'max:255'],
            'identity_issue_date' => ['nullable', 'date', 'before_or_equal:today'],
            'permanent_address' => ['nullable', 'string', 'max:500'],
        ];
    }
}
