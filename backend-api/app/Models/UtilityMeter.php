<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
// Công tơ điện
class UtilityMeter extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'room_id',
        'service_id',
        'meter_code',
        'initial_reading',
        'is_active',
    ];

    protected $casts = ['initial_reading' => 'decimal:2', 'is_active' => 'boolean'];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function readings()
    {
        return $this->hasMany(UtilityReading::class)->orderBy('reading_date');
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function latestReading()
    {
        return $this->hasOne(UtilityReading::class)->latestOfMany('reading_date');
    }
}
