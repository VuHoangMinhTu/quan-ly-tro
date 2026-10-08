<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BoardingHouse extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'landlord_id',
        'name',
        'address',
        'description',
    ];

    public function landlord()
    {
        return $this->belongsTo(Landlord::class);
    }

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }

    public function services()
    {
        return $this->hasMany(Service::class);
    }
}
