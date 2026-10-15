<?php

namespace App\Http\Requests\Contract;

use App\Services\BoardingHouseAccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ContractScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $access = app(BoardingHouseAccessService::class);
        $house = $access->house($this->user(), (int) $this->route('id'));

        return $access->isOwner($this->user(), $house);
    }

    public function rules(): array
    {
        return ['default_invoice_issue_day_from' => ['nullable', 'integer', 'between:1,31'],
            'default_invoice_issue_day_to' => ['nullable', 'integer', 'between:1,31'],
            'default_payment_due_day' => ['nullable', 'integer', 'between:1,31']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $validator->errors()->any() && $this->filled('default_invoice_issue_day_from') && $this->filled('default_invoice_issue_day_to')
                && $this->integer('default_invoice_issue_day_from') > $this->integer('default_invoice_issue_day_to')) {
                $validator->errors()->add('default_invoice_issue_day_to', 'Ngày cuối gửi hóa đơn phải từ ngày bắt đầu trở đi.');
            }
        });
    }
}
