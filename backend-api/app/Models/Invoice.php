<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentCode;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class Invoice extends Model
{
    use HasDocumentCode;
    use SoftDeletes;

    protected $fillable = [
        'room_id',
        'contract_id',
        'invoice_code',
        'subtotal',
        'billing_period',
        'discount_amount',
        'total_amount',
        'paid_amount',
        'status',
        'issued_at',
        'due_date',
        'paid_at',
        'note',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'billing_period' => 'date:Y-m-d',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'due_date' => 'date:Y-m-d',
        'paid_at' => 'datetime',
        'issued_at' => 'date:Y-m-d',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class)->orderBy('paid_at');
    }

    public function payosPaymentRequests()
    {
        return $this->hasMany(PayOSPaymentRequest::class);
    }

    public function latestPayosPaymentRequest()
    {
        return $this->hasOne(PayOSPaymentRequest::class)->latestOfMany();
    }

    public function payosPaymentRequest()
    {
        return $this->hasOne(PayOSPaymentRequest::class)
            ->whereIn('status', PayOSPaymentRequest::REUSABLE_STATUSES)
            ->latest('id');
    }

    public function recalculateTotals(): void
    {
        $subtotal = BigDecimal::of(0);
        foreach ($this->items()->pluck('amount') as $amount) {
            $subtotal = $subtotal->plus((string) $amount);
        }
        if (BigDecimal::of($this->discount_amount)->isGreaterThan($subtotal)) {
            throw ValidationException::withMessages(['discount_amount' => 'Số tiền giảm giá không được vượt quá tạm tính.']);
        }
        $this->update(['subtotal' => (string) $subtotal, 'total_amount' => (string) $subtotal->minus($this->discount_amount)]);
    }

    /**
     * Financial data may be changed before any payment exists. UNPAID is still
     * editable because it only means the invoice was issued, not that money was
     * received. Once paid_amount is positive, adjustments need a separate flow.
     */
    public function canEditFinancials(): bool
    {
        return $this->status === 'DRAFT'
            || ($this->status === 'UNPAID' && (float) $this->paid_amount === 0.0);
    }
}
