<?php

namespace App\Http\Requests\Concerns;

trait ContractServicePricingRules
{
    protected function servicePricingRules(string $prefix = ''): array
    {
        return [
            $prefix.'base_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            $prefix.'vat_rate' => ['nullable', 'numeric', 'between:0,100', 'decimal:0,2'],
            $prefix.'pricing_snapshot' => ['nullable', 'array', 'max:100'],
            $prefix.'pricing_snapshot.*.from_quantity' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            $prefix.'pricing_snapshot.*.to_quantity' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            $prefix.'pricing_snapshot.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
        ];
    }
}
