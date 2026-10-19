<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractHandoverReport extends Model
{
    protected $fillable = ['contract_id', 'type', 'status', 'items', 'confirmed_at'];

    protected $casts = ['items' => 'array', 'confirmed_at' => 'datetime'];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
