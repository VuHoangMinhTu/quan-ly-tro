<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'invoice_id',
        'idempotency_key',
        'idempotency_fingerprint',
        'amount',
        'payment_method',
        'payment_source',
        'reference_code',
        'external_reference',
        'paid_at',
        'status',
        'note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    protected $hidden = ['idempotency_key', 'idempotency_fingerprint'];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
