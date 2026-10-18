<?php

namespace App\Http\Requests\Contract;

use App\Http\Requests\Concerns\ContractServicePricingRules;
use App\Services\BoardingHouseAccessService;
use App\Services\ContractService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContractRequest extends FormRequest
{
    use ContractServicePricingRules;

    public function authorize(): bool
    {
        if ($this->route('id') !== null) {
            app(ContractService::class)->find($this->user(), (int) $this->route('id'));
        } else {
            app(BoardingHouseAccessService::class)->room($this->user(), (int) $this->route('roomId'));
        }

        return true;
    }

    public function rules(): array
    {
        $updating = $this->route('id') !== null;

        return [
            'tenant_id' => [$updating ? 'sometimes' : 'required', 'integer', 'min:1', 'exists:tenants,id'],
            'contract_code' => [$updating ? 'sometimes' : 'required', 'string', 'max:100', Rule::unique('contracts', 'contract_code')->ignore($this->route('id'))],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'signed_date' => ['nullable', 'date_format:Y-m-d'],
            'monthly_rent' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'deposit_amount' => ['sometimes', 'numeric', 'min:0', 'max:9999999999999.99'],
            'status' => ['sometimes', Rule::in(['DRAFT', 'ACTIVE', 'EXPIRED', 'TERMINATED'])],
            'note' => ['nullable', 'string'],
            'lessor_representative_user_id' => ['nullable', 'integer', 'min:1'],
            'payment_due_day' => ['nullable', 'integer', 'between:1,31'],
            'contract_template_id' => ['nullable', 'integer', 'min:1'],
            'occupant_tenant_ids' => ['sometimes', 'array', 'max:100'],
            'occupant_tenant_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'service_ids' => ['sometimes', 'array', 'max:100'],
            'service_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'services' => ['sometimes', 'array', 'max:100'],
            'services.*.service_id' => ['required', 'integer', 'min:1', 'distinct'],
            ...$this->servicePricingRules('services.*.'),
            'utility_openings' => ['sometimes', 'array', 'max:100'],
            'utility_openings.*.utility_meter_id' => ['required', 'integer', 'min:1', 'distinct'],
            'utility_openings.*.opening_reading' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'utility_openings.*.reading_date' => ['required', 'date_format:Y-m-d'],
            'move_out_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function attributes(): array
    {
        return ['tenant_id' => 'người thuê', 'contract_code' => 'mã hợp đồng', 'start_date' => 'ngày bắt đầu',
            'end_date' => 'ngày kết thúc', 'signed_date' => 'ngày ký', 'monthly_rent' => 'giá thuê thỏa thuận',
            'deposit_amount' => 'tiền cọc thỏa thuận', 'lessor_representative_user_id' => 'đại diện Bên A',
            'occupant_tenant_ids' => 'người ở cùng', 'service_ids' => 'dịch vụ', 'utility_openings' => 'chỉ số đầu',
            'payment_due_day' => 'ngày thanh toán hàng tháng'];
    }
}
