<?php

namespace App\Http\Requests\Contract;

use App\Http\Requests\Concerns\ValidatesActiveContractOverlap;
use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreContractRequest extends FormRequest
{
    use ValidatesActiveContractOverlap;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'contract_code' => ['required', 'string', 'max:100', Rule::unique('contracts', 'contract_code')],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'signed_date' => ['nullable', 'date'],
            'monthly_rent' => ['required', 'numeric', 'min:0'],
            'deposit_amount' => ['required', 'numeric', 'min:0'],
            'status' => ['required', Rule::in(['DRAFT', 'ACTIVE', 'EXPIRED', 'TERMINATED'])],
            'note' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validateActiveContractOverlap($validator, $this->ownedRoom()));
    }

    private function ownedRoom(): ?Room
    {
        return Room::query()
            ->whereKey($this->route('roomId'))
            ->whereHas('boardingHouse', fn ($query) => $query->where('landlord_id', $this->user()?->landlord?->id))
            ->first();
    }
}
