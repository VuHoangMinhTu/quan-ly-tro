<?php

namespace App\Http\Requests\Contract;

use Illuminate\Foundation\Http\FormRequest;

class PreviewContractTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->landlord()->exists();
    }

    public function rules(): array
    {
        return ['content_html' => ['required', 'string', 'max:200000']];
    }
}
