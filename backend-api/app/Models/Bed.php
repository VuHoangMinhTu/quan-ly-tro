<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bed extends Model
{
    protected $fillable = ['room_id', 'bed_code', 'floor_label', 'monthly_rent', 'status', 'note'];

    protected $casts = ['monthly_rent' => 'decimal:2'];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function roomTenants(): HasMany
    {
        return $this->hasMany(RoomTenant::class);
    }
}
