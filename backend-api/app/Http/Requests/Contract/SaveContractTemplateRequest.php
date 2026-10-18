<?php

namespace App\Http\Requests\Contract;

use Illuminate\Foundation\Http\FormRequest;

class SaveContractTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->landlord()->exists();
    }

    public function rules(): array
    {
        $required = $this->route('id') === null ? 'required' : 'sometimes';

        return [
            'template_key' => [$required, 'string', 'max:80', 'regex:/^[A-Z][A-Z0-9_]*$/'],
            'name' => [$required, 'string', 'min:1', 'max:255'],
            'content_html' => [$required, 'string', 'min:1', 'max:200000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['template_key' => 'mã mẫu', 'name' => 'tên mẫu', 'content_html' => 'nội dung mẫu'];
    }
}
