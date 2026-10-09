<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\StorePaymentRequest;
use App\Http\Requests\Payment\UpdatePaymentRequest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoicePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function index(Request $r, int $invoiceId): JsonResponse
    {
        return ApiResponse::success($this->invoice($r, $invoiceId)->findOrFail($invoiceId)->payments, 'Lấy danh sách thanh toán thành công.');
    }

    public function store(StorePaymentRequest $r, int $invoiceId): JsonResponse
    {
        DB::transaction(function () use ($r, $invoiceId) {
            $i = $this->invoice($r, $invoiceId)->lockForUpdate()->firstOrFail();
            $this->allowed($i, $r->validated()['amount']);
            $i->payments()->create($r->validated());
            app(InvoicePaymentService::class)->recalculate($i);
        });

        return ApiResponse::success(null, 'Ghi nhận thanh toán thành công.', 201);
    }

    public function show(Request $r, int $id): JsonResponse
    {
        return ApiResponse::success($this->payment($r, $id), 'Lấy thông tin thanh toán thành công.');
    }

    public function update(UpdatePaymentRequest $r, int $id): JsonResponse
    {
        $p = $this->payment($r, $id);
        $this->manualOnly($p);
        DB::transaction(function () use ($p, $r) {
            $i = Invoice::lockForUpdate()->findOrFail($p->invoice_id);
            $this->allowed($i, $r->validated()['amount'], $p->id);
            $p->update($r->validated());
            app(InvoicePaymentService::class)->recalculate($i);
        });

        return ApiResponse::success(null, 'Cập nhật thanh toán thành công.');
    }

    public function destroy(Request $r, int $id): JsonResponse
    {
        $p = $this->payment($r, $id);
        $this->manualOnly($p);
        DB::transaction(function () use ($p) {
            $i = Invoice::lockForUpdate()->findOrFail($p->invoice_id);
            $p->delete();
            app(InvoicePaymentService::class)->recalculate($i);
        });

        return ApiResponse::success(null, 'Xóa thanh toán thành công.');
    }

    private function invoice(Request $r, int $id)
    {
        return Invoice::whereHas('room.boardingHouse', fn ($q) => $q->where('landlord_id', $r->user()->landlord->id))->whereKey($id);
    }

    private function payment(Request $r, int $id): Payment
    {
        return Payment::whereHas('invoice.room.boardingHouse', fn ($q) => $q->where('landlord_id', $r->user()->landlord->id))->findOrFail($id);
    }

    private function allowed(Invoice $i, $amount, ?int $ignore = null): void
    {
        if (! in_array($i->status, ['UNPAID', 'PARTIALLY_PAID'], true) || $i->total_amount <= 0) {
            throw ValidationException::withMessages(['status' => 'Chỉ có thể ghi nhận thanh toán cho hóa đơn chưa thanh toán hoặc thanh toán một phần.']);
        }$paid = $i->payments()->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->sum('amount');
        if ($amount > $i->total_amount - $paid) {
            throw ValidationException::withMessages(['amount' => 'Số tiền thanh toán không được vượt quá số tiền còn lại.']);
        }
    }

    private function manualOnly(Payment $payment): void
    {
        if ($payment->payment_source === 'PAYOS_WEBHOOK') {
            throw ValidationException::withMessages(['payment' => 'Thanh toán payOS chỉ được thay đổi qua webhook đã xác minh.']);
        }
    }
}
