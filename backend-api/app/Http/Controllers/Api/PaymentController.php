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
use Brick\Math\BigDecimal;
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
        $values = $r->safe()->except(['request_key']);
        $key = $r->validated('request_key');
        $fingerprint = $key === null ? null : $this->fingerprint($values);

        // Resolve the owner's landlord before the transaction. Under MySQL
        // REPEATABLE READ, a lookup before the invoice lock would freeze a stale snapshot.
        $ownedInvoice = $this->invoice($r, $invoiceId);
        $replayed = DB::transaction(function () use ($ownedInvoice, $values, $key, $fingerprint): bool {
            $i = $ownedInvoice->lockForUpdate()->firstOrFail();
            if ($key !== null) {
                $request = DB::table('manual_payment_requests')
                    ->where('invoice_id', $i->id)->where('request_key', strtolower($key));
                $previous = $request->value('payload_hash');
                if ($previous !== null) {
                    if (! hash_equals($previous, $fingerprint)) {
                        throw ValidationException::withMessages(['request_key' => 'Mã giao dịch này đã được dùng với dữ liệu khác.']);
                    }

                    return true;
                }

                DB::table('manual_payment_requests')->insert([
                    'invoice_id' => $i->id, 'request_key' => strtolower($key), 'payload_hash' => $fingerprint,
                ]);
            }

            $this->allowed($i, $values['amount']);
            $i->payments()->create($values);
            app(InvoicePaymentService::class)->recalculate($i);

            return false;
        });

        if ($replayed) {
            return ApiResponse::success(['replayed' => true], 'Yêu cầu thanh toán này đã được xử lý trước đó.');
        }

        return ApiResponse::success(null, 'Ghi nhận thanh toán thành công.', 201);
    }

    private function fingerprint(array $values): string
    {
        return hash('sha256', json_encode([
            (string) BigDecimal::of((string) $values['amount'])->strippedOfTrailingZeros(),
            $values['payment_method'],
            $values['paid_at'],
            $values['reference_code'] ?? null,
            $values['note'] ?? null,
        ], JSON_THROW_ON_ERROR));
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
