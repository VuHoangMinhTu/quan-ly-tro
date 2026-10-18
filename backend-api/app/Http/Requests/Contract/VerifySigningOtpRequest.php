<?php

namespace App\Http\Requests\Contract;

use Illuminate\Foundation\Http\FormRequest;

class VerifySigningOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['otp' => ['required', 'string', 'regex:/\A[0-9]{6}\z/']];
    }

    public function messages(): array
    {
        return ['otp.regex' => 'Vui lòng nhập mã OTP gồm 6 chữ số.'];
    }
}
