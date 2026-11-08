<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\StorePaymentRequest;
use App\Http\Requests\Payment\UpdatePaymentRequest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\BoardingHouseAccessService;
use App\Services\InvoicePaymentService;
use App\Services\ManualPaymentIdempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function index(Request $r, int $invoiceId, BoardingHouseAccessService $access): JsonResponse
    {
        $invoice = Invoice::query()->whereHas('room', fn ($query) => $query->whereIn('boarding_house_id', $access->houses($r->user())->select('boarding_houses.id')))->findOrFail($invoiceId);

        return ApiResponse::success($invoice->payments, 'Lấy danh sách thanh toán thành công.');
    }

    public function store(StorePaymentRequest $r, int $invoiceId): JsonResponse
    {
        $outcome = DB::transaction(function () use ($r, $invoiceId) {
            $i = $this->invoice($r, $invoiceId)->lockForUpdate()->firstOrFail();
            $data = $r->validated();
            $ledger = app(ManualPaymentIdempotency::class);
            $landlordId = (int) $r->user()->landlord->id;
            if (! empty($data['idempotency_key'])) {
                $data['idempotency_fingerprint'] = $ledger->fingerprint($data);
                $result = $ledger->claim($i, $landlordId, $data['idempotency_key'], $data['idempotency_fingerprint']);
                if ($result === 'MISSING') {
                    if (in_array($i->status, ['UNPAID', 'PARTIALLY_PAID', 'PAID'], true)) {
                        app(InvoicePaymentService::class)->recalculate($i);
                    }

                    return 'DELETED';
                }
                if ($result !== 'NEW') {
                    return $result;
                }
            }
            $this->allowed($i, $data['amount']);
            $payment = $i->payments()->create($data);
            if (! empty($data['idempotency_key'])) {
                $ledger->complete($payment, $landlordId);
            }
            app(InvoicePaymentService::class)->recalculate($i);

            return 'APPLIED';
        }, 3);
        abort_if($outcome === 'DELETED', 409, 'Khoản thu của yêu cầu này đã bị xóa hoặc hủy ghi nhận. Kiểm tra hóa đơn trước khi ghi một khoản thu mới.');

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
            $i = $this->invoice($r, $p->invoice_id)->lockForUpdate()->firstOrFail();
            $p = Payment::whereKey($p->id)->where('invoice_id', $i->id)->lockForUpdate()->firstOrFail();
            $this->manualOnly($p);
            $this->allowed($i, $r->validated()['amount'], $p->id);
            $p->update($r->validated());
            app(InvoicePaymentService::class)->recalculate($i);
        }, 3);

        return ApiResponse::success(null, 'Cập nhật thanh toán thành công.');
    }

    public function destroy(Request $r, int $id): JsonResponse
    {
        $p = $this->payment($r, $id);
        $this->manualOnly($p);
        DB::transaction(function () use ($p, $r) {
            $i = $this->invoice($r, $p->invoice_id)->lockForUpdate()->firstOrFail();
            $p = Payment::whereKey($p->id)->where('invoice_id', $i->id)->lockForUpdate()->firstOrFail();
            $this->manualOnly($p);
            app(ManualPaymentIdempotency::class)->deleted($p, (int) $r->user()->landlord->id, $r->user()->id);
            $p->delete();
            app(InvoicePaymentService::class)->recalculate($i);
        }, 3);

        return ApiResponse::success(null, 'Xóa thanh toán thành công.');
    }

    private function invoice(Request $r, int $id)
    {
        return Invoice::whereHas('room.boardingHouse', fn ($q) => $q->where('landlord_id', $r->user()->landlord?->id))->whereKey($id);
    }

    private function payment(Request $r, int $id): Payment
    {
        return Payment::whereHas('invoice.room.boardingHouse', fn ($q) => $q->where('landlord_id', $r->user()->landlord?->id))->findOrFail($id);
    }

    private function allowed(Invoice $i, $amount, ?int $ignore = null): void
    {
        if (! in_array($i->status, ['UNPAID', 'PARTIALLY_PAID'], true) || $i->total_amount <= 0) {
            throw ValidationException::withMessages(['status' => 'Chỉ có thể ghi nhận thanh toán cho hóa đơn chưa thanh toán hoặc thanh toán một phần.']);
        }$paid = $i->payments()->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->lockForUpdate()->sum('amount');
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
