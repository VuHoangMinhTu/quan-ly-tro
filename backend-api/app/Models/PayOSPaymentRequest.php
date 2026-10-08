<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayOSPaymentRequest extends Model
{
    public const REUSABLE_STATUSES = ['PENDING', 'PROCESSING', 'ACTIVE'];

    protected $table = 'payos_payment_requests';

    protected $fillable = [
        'invoice_id',
        'order_code',
        'payment_link_id',
        'checkout_url',
        'qr_code',
        'amount',
        'status',
        'expired_at',
        'cancelled_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expired_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isReusableFor(float $remainingAmount): bool
    {
        return in_array($this->status, self::REUSABLE_STATUSES, true)
            && (float) $this->amount === $remainingAmount
            && filled($this->payment_link_id)
            && filled($this->checkout_url)
            && filled($this->qr_code);
    }
}
