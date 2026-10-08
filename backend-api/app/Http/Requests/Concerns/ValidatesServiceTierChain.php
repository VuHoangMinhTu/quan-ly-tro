<?php

namespace App\Http\Requests\Concerns;

use App\Models\Service;
use Illuminate\Validation\Validator;

trait ValidatesServiceTierChain
{
    protected function validateServiceTierChain(Validator $validator, ?Service $service, ?int $currentTierId = null): void
    {
        if (! $service || $validator->errors()->isNotEmpty()) {
            return;
        }

        if ($service->billing_method !== 'TIERED') {
            $validator->errors()->add('service_id', 'Price tiers are only allowed for TIERED services.');

            return;
        }

        $tiers = $service->priceTiers()
            ->get()
            ->reject(fn ($tier) => $tier->id === $currentTierId)
            ->map(fn ($tier) => [
                'id' => $tier->id,
                'from_quantity' => (float) $tier->from_quantity,
                'to_quantity' => $tier->to_quantity === null ? null : (float) $tier->to_quantity,
                'tier_order' => $tier->tier_order,
            ])
            ->all();

        $tiers[] = [
            'id' => $currentTierId,
            'from_quantity' => (float) $this->input('from_quantity'),
            'to_quantity' => $this->input('to_quantity') === null ? null : (float) $this->input('to_quantity'),
            'tier_order' => (int) $this->input('tier_order'),
        ];

        usort($tiers, fn (array $left, array $right) => $left['tier_order'] <=> $right['tier_order']);

        foreach ($tiers as $index => $tier) {
            $previous = $tiers[$index - 1] ?? null;
            $next = $tiers[$index + 1] ?? null;

            if ($next && $tier['to_quantity'] === null) {
                $validator->errors()->add('to_quantity', 'Only the final tier may have no upper quantity.');
            }

            if ($previous) {
                if ($previous['tier_order'] === $tier['tier_order']) {
                    $validator->errors()->add('tier_order', 'The tier order must be unique for this service.');
                } elseif ($previous['to_quantity'] === null) {
                    $validator->errors()->add('tier_order', 'A tier cannot follow an open-ended tier.');
                } elseif ($tier['from_quantity'] !== $previous['to_quantity']) {
                    $validator->errors()->add('from_quantity', 'The tier must start where the previous tier ends.');
                }
            }
        }
    }
}
