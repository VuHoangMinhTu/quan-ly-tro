<?php

/**
 * @property \App\Models\Landlord|null $landlord
 */
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Landlord extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'full_name',
        'phone',
        'address',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function boardingHouses()
    {
        return $this->hasMany(BoardingHouse::class);
    }

    public function tenants()
    {
        return $this->hasMany(Tenant::class);
    }
}
