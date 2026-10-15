<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class BoardingHouse extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'landlord_id',
        'name',
        'address',
        'description',
        'default_invoice_issue_day_from',
        'default_invoice_issue_day_to',
        'default_payment_due_day',
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

    public function managers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'boarding_house_managers')->withTimestamps();
    }

    public function residenceProfile(): HasOne
    {
        return $this->hasOne(BoardingHouseResidenceProfile::class);
    }
}
