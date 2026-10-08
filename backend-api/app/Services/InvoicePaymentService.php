<?php

namespace App\Services;

use App\Models\Invoice;

class InvoicePaymentService
{
    public function recalculate(Invoice $invoice): void
    {
        $oldRemaining = (float) $invoice->total_amount - (float) $invoice->paid_amount;
        $paid = $invoice->payments()->sum('amount');
        $status = $paid == 0 ? 'UNPAID' : ($paid >= $invoice->total_amount ? 'PAID' : 'PARTIALLY_PAID');
        $invoice->update(['paid_amount' => $paid, 'status' => $status, 'paid_at' => $status === 'PAID' ? $invoice->payments()->max('paid_at') : null]);
        $updatedInvoice = $invoice->fresh();
        app(PayOSService::class)->invalidateRequestsForAmountChange(
            $updatedInvoice,
            $oldRemaining,
            (float) $updatedInvoice->total_amount - (float) $updatedInvoice->paid_amount,
        );
    }
}
