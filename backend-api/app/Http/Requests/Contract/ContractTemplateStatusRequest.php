<?php

namespace App\Http\Requests\Contract;

use Illuminate\Foundation\Http\FormRequest;

class ContractTemplateStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->landlord()->exists();
    }

    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean']];
    }
}
