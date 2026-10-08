<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Room extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'boarding_house_id',
        'room_code',
        'room_name',
        'area',
        'monthly_rent',
        'max_tenants',
        'status',
        'description',
    ];

    protected $casts = [
        'area' => 'decimal:2',
        'monthly_rent' => 'decimal:2',
        'max_tenants' => 'integer',
    ];

    public function boardingHouse()
    {
        return $this->belongsTo(BoardingHouse::class);
    }

    public function amenities()
    {
        return $this->belongsToMany(
            Amenity::class,
            'room_amenities'
        );
    }

    public function tenants()
    {
        return $this->belongsToMany(
            Tenant::class,
            'room_tenants'
        )
            ->withPivot(
                'id',
                'is_primary',
                'move_in_date',
                'move_out_date',
                'status'
            )
            ->withTimestamps();
    }

    public function roomTenants()
    {
        return $this->hasMany(RoomTenant::class);
    }

    public function contracts()
    {
        return $this->hasMany(Contract::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function utilityMeters()
    {
        return $this->hasMany(UtilityMeter::class);
    }
}
