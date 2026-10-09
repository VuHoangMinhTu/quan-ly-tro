<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'boarding_house_id',
        'name',
        'type',
        'billing_method',
        'unit',
        'base_price',
        'is_active',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function boardingHouse()
    {
        return $this->belongsTo(BoardingHouse::class);
    }

    public function priceTiers()
    {
        return $this->hasMany(ServicePriceTier::class)->orderBy('tier_order');
    }

    public function utilityMeters()
    {
        return $this->hasMany(UtilityMeter::class);
    }

    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class, 'room_services')
            ->withPivot('is_active')
            ->withTimestamps();
    }
}
