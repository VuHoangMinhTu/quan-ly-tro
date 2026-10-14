<?php

namespace App\Http\Requests\Residence;

use App\Services\BoardingHouseAccessService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AssignBoardingHouseManagersRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $access = app(BoardingHouseAccessService::class);

        return $access->isOwner($this->user(), $access->house($this->user(), (int) $this->route('id')));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'manager_emails' => ['present', 'array', 'max:100'],
            'manager_emails.*' => ['required', 'email', 'distinct', 'exists:users,email'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('manager_emails'))) {
            $this->merge(['manager_emails' => array_map(fn ($email) => is_string($email) ? mb_strtolower(trim($email)) : $email, $this->input('manager_emails'))]);
        }
    }

    public function attributes(): array
    {
        return ['manager_emails' => 'danh sách email quản lý', 'manager_emails.*' => 'email quản lý'];
    }
}
