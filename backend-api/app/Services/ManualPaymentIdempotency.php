<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManualPaymentIdempotency
{
    public function fingerprint(array $data): string
    {
        return hash('sha256', json_encode([
            'amount' => (string) BigDecimal::of($data['amount'])->toScale(2, RoundingMode::HalfUp),
            'payment_method' => $data['payment_method'],
            'paid_at' => CarbonImmutable::parse($data['paid_at'])->format('Y-m-d H:i:s'),
            'reference_code' => $data['reference_code'] ?? null,
            'note' => $data['note'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    /** Called under the invoice lock; the claim and receipt must commit together. */
    public function claim(Invoice $invoice, int $landlordId, string $key, string $fingerprint): string
    {
        $scope = ['landlord_id' => $landlordId, 'invoice_id' => $invoice->id, 'idempotency_key' => $key];
        $created = DB::table('manual_payment_requests')->insertOrIgnore([
            ...$scope, 'idempotency_fingerprint' => $fingerprint, 'state' => 'APPLIED',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $record = DB::table('manual_payment_requests')->where($scope)->lockForUpdate()->first();
        if (! $record) {
            throw new \RuntimeException('Unable to register manual payment request.');
        }
        $this->samePayload($record->idempotency_fingerprint, $fingerprint);
        if ($created) {
            return 'NEW';
        }
        if ($record->state === 'DELETED') {
            return 'DELETED';
        }
        $receipt = $invoice->payments()->whereKey($record->payment_id)->lockForUpdate()->first();
        if (! $receipt) {
            DB::table('manual_payment_requests')->where('id', $record->id)->update([
                'state' => 'DELETED', 'deleted_at' => now(), 'updated_at' => now(),
            ]);

            return 'MISSING';
        }

        return 'APPLIED';
    }

    public function complete(Payment $payment, int $landlordId): void
    {
        $updated = DB::table('manual_payment_requests')->where([
            'landlord_id' => $landlordId, 'invoice_id' => $payment->invoice_id,
            'idempotency_key' => $payment->idempotency_key,
        ])->update(['payment_id' => $payment->id, 'updated_at' => now()]);
        if ($updated !== 1) {
            throw new \RuntimeException('Unable to link manual payment replay evidence.');
        }
    }

    public function deleted(Payment $payment, int $landlordId, int $actorId): void
    {
        if (! $payment->idempotency_key) {
            return;
        }
        if (! $payment->idempotency_fingerprint) {
            abort(409, 'Khoản thu thiếu dấu vết yêu cầu. Cần đối soát trước khi xóa.');
        }
        $scope = ['landlord_id' => $landlordId, 'invoice_id' => $payment->invoice_id, 'idempotency_key' => $payment->idempotency_key];
        DB::table('manual_payment_requests')->insertOrIgnore([
            ...$scope, 'idempotency_fingerprint' => $payment->idempotency_fingerprint,
            'payment_id' => $payment->id, 'state' => 'APPLIED', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $record = DB::table('manual_payment_requests')->where($scope)->lockForUpdate()->first();
        $this->samePayload($record->idempotency_fingerprint, $payment->idempotency_fingerprint);
        abort_if((int) $record->payment_id !== $payment->id, 409, 'Dấu vết yêu cầu không khớp khoản thu. Cần đối soát trước khi xóa.');
        DB::table('manual_payment_requests')->where('id', $record->id)->update([
            'state' => 'DELETED', 'deleted_at' => now(), 'deleted_by' => $actorId, 'updated_at' => now(),
        ]);
    }

    private function samePayload(string $stored, string $received): void
    {
        if (! hash_equals($stored, $received)) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Lần gửi trước đã được ghi nhận với nội dung khác. Tải lại hóa đơn để kiểm tra trước khi tạo khoản thu mới.',
            ]);
        }
    }
}
