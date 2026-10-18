<?php

namespace App\Http\Requests\Contract;

use Illuminate\Foundation\Http\FormRequest;

class SubmitContractSignatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'consent' => ['required', 'accepted'],
            'consent_version' => ['required', 'string', 'max:40'],
            'content_hash' => ['required', 'string', 'size:64'],
            'verification_token' => ['required', 'string', 'size:64'],
            'signature' => ['required', 'file', 'mimes:png', 'max:512', 'dimensions:min_width=80,min_height=30,max_width=2000,max_height=1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'consent.accepted' => 'Vui lòng xác nhận đã đọc và đồng ý với nội dung hợp đồng.',
            'signature.required' => 'Vui lòng vẽ chữ ký trước khi xác nhận.',
            'signature.mimes' => 'Chữ ký phải là ảnh PNG hợp lệ.',
            'signature.max' => 'Ảnh chữ ký không được vượt quá 512 KB.',
            'signature.dimensions' => 'Kích thước ảnh chữ ký không hợp lệ.',
        ];
    }
}
