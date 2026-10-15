<?php

namespace App\Models;

use Database\Factories\ContractUtilityOpeningFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractUtilityOpening extends Model
{
    /** @use HasFactory<ContractUtilityOpeningFactory> */
    use HasFactory;

    protected $fillable = ['utility_meter_id', 'service_id', 'service_name', 'unit', 'opening_reading', 'reading_date'];

    protected $casts = ['opening_reading' => 'decimal:2', 'reading_date' => 'date:Y-m-d'];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
