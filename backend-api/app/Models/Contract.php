<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contract extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'room_id',
        'tenant_id',
        'contract_code',
        'start_date',
        'end_date',
        'signed_date',
        'monthly_rent',
        'deposit_amount',
        'status',
        'note',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'signed_date' => 'date:Y-m-d',
        'monthly_rent' => 'decimal:2',
        'deposit_amount' => 'decimal:2',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }
}
