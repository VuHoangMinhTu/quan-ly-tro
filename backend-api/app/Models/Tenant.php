<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'landlord_id',
        'full_name',
        'phone',
        'email',
        'date_of_birth',
        'gender',
        'identity_number',
        'identity_issue_place',
        'identity_issue_date',
        'permanent_address',
    ];

    protected $casts = [
        'date_of_birth' => 'date:Y-m-d',
        'identity_issue_date' => 'date:Y-m-d',
    ];

    public function landlord()
    {
        return $this->belongsTo(Landlord::class);
    }

    public function contracts()
    {
        return $this->hasMany(Contract::class);
    }

    public function roomTenants()
    {
        return $this->hasMany(RoomTenant::class);
    }

    public function rooms()
    {
        return $this->belongsToMany(
            Room::class,
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
}
