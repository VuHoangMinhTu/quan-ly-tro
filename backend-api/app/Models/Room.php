<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Room extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'boarding_house_id',
        'floor_id',
        'room_type',
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

    public function scopeWithActiveOccupancy(Builder $query): void
    {
        $query->withCount(['roomTenants as active_occupants_count' => fn ($occupants) => $occupants->where('status', 'ACTIVE')->whereHas('tenant')]);
    }

    public function boardingHouse()
    {
        return $this->belongsTo(BoardingHouse::class);
    }

    public function floor()
    {
        return $this->belongsTo(Floor::class);
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

    public function beds()
    {
        return $this->hasMany(Bed::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function utilityMeters()
    {
        return $this->hasMany(UtilityMeter::class);
    }

    public function residenceDeclarations()
    {
        return $this->hasMany(ResidenceDeclaration::class);
    }

    public function hasHistory(): bool
    {
        return $this->contracts()->exists()
            || $this->roomTenants()->exists()
            || $this->invoices()->exists()
            || $this->utilityMeters()->exists()
            || $this->services()->exists()
            || $this->residenceDeclarations()->exists();
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'room_services')
            ->withPivot('is_active')
            ->withTimestamps();
    }
}
