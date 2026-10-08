<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
// Số lần ghi lại của công tơ điện
class UtilityReading extends Model
{
    protected $fillable = [
        'utility_meter_id',
        'reading_date',
        'reading_value',
        'note',
    ];

    protected $casts = [
        'reading_date' => 'date:Y-m-d',
        'reading_value' => 'decimal:2',
    ];

    public function meter()
    {
        return $this->belongsTo(UtilityMeter::class, 'utility_meter_id');
    }
}
