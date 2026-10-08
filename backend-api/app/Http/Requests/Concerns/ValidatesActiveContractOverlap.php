<?php

namespace App\Http\Requests\Concerns;

use App\Models\Contract;
use App\Models\Room;
use Illuminate\Validation\Validator;

trait ValidatesActiveContractOverlap
{
    protected function validateActiveContractOverlap(Validator $validator, ?Room $room, ?int $ignoreContractId = null): void
    {
        if (! $room || $validator->errors()->isNotEmpty() || $this->input('status') !== 'ACTIVE') {
            return;
        }

        $endDate = $this->input('end_date') ?? '9999-12-31';

        $overlaps = Contract::query()
            ->where('room_id', $room->id)
            ->where('status', 'ACTIVE')
            ->when($ignoreContractId, fn ($query) => $query->whereKeyNot($ignoreContractId))
            ->whereDate('start_date', '<=', $endDate)
            ->where(fn ($query) => $query
                ->whereNull('end_date')
                ->orWhereDate('end_date', '>=', $this->input('start_date')))
            ->exists();

        if ($overlaps) {
            $validator->errors()->add('start_date', 'The active contract overlaps an existing active contract for this room.');
        }
    }
}
