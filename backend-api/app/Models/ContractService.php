<?php

namespace App\Models;

use Database\Factories\ContractServiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractService extends Model
{
    /** @use HasFactory<ContractServiceFactory> */
    use HasFactory;

    protected $fillable = ['service_id', 'service_name', 'service_type', 'billing_method', 'unit', 'base_price', 'vat_rate', 'pricing_snapshot', 'is_active', 'effective_from', 'effective_to', 'adjustment_reason', 'created_by_user_id'];

    protected $casts = ['base_price' => 'decimal:2', 'vat_rate' => 'decimal:2', 'pricing_snapshot' => 'array', 'is_active' => 'boolean', 'effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d'];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
