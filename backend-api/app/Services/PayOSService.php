<?php

namespace App\Services;

use App\Exceptions\PayOSReconciliationException;
use App\Models\Invoice;
use App\Models\PayOSPaymentRequest;
use App\Models\Payment;
use Carbon\Carbon;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PayOS\Models\V2\PaymentRequests\CreatePaymentLinkRequest;
use PayOS\Models\V2\PaymentRequests\CreatePaymentLinkResponse;
use PayOS\Models\V2\PaymentRequests\PaymentLink;
use PayOS\Models\Webhooks\ConfirmWebhookResponse;
use PayOS\Models\Webhooks\WebhookData;
use PayOS\Exceptions\NotFoundException;
use PayOS\PayOS;
use PayOS\PayOSOptions;
use RuntimeException;

class PayOSService
{
    private const ORDER_CODE_OFFSET = 9_000_000_000;

    public function createOrReuse(Invoice $invoice): PayOSPaymentRequest
    {
        // Chốt số tiền còn lại dưới row lock để không đọc trúng invoice đang
        // được webhook hoặc một luồng thanh toán khác cập nhật đồng thời.
        $amount = DB::transaction(function () use ($invoice) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            return $this->remainingAmount($invoice);
        });

        // Fast path: request trong REUSABLE_STATUSES chỉ được dùng lại khi đúng
        // số tiền và đã có đủ payment_link_id, checkout URL, QR. Check sớm giúp
        // các lần click lại/retry không gọi PayOS thêm một lần không cần thiết.
        if ($existing = $this->usableRequest($invoice, $amount)) {
            return $this->withAction($existing, 'reused');
        }

        /*
         * "Reconcile" = hỏi PayOS về order code/payment link đang có, rồi đồng bộ
         * trạng thái thật về DB local trước khi quyết định tạo QR mới.
         *
         * CREATING / CREATION_FAILED / RECONCILIATION_REQUIRED là trạng thái local
         * dùng cho request "mơ hồ": lệnh có thể đã tới PayOS nhưng tiến trình local
         * timeout/lỗi trước khi biết hoặc lưu được trạng thái remote. Phải đối soát
         * từng request này trước; nếu tạo order mới ngay, một hóa đơn có thể có hai
         * QR còn hoạt động.
         */
        $pendingReconciliation = $invoice->payosPaymentRequests()
            ->whereIn('status', ['CREATING', 'CREATION_FAILED', 'RECONCILIATION_REQUIRED'])
            ->oldest('id')
            ->get();

        foreach ($pendingReconciliation as $localRequest) {
            $reconciled = $this->reconcilePaymentRequest($localRequest);

            if ($reconciled->isReusableFor($amount)) {
                // Remote đã xác nhận order vẫn usable; trả lại request vừa được
                // hòa giải thay vì tạo QR/order mới cho cùng khoản phải thu.
                return $this->withAction($reconciled, 'reconciled');
            }

            if (in_array($reconciled->status, ['PAID', 'UNDERPAID'], true)) {
                throw new PayOSReconciliationException(
                    'The existing payOS payment request has received payment and is awaiting verified payment reconciliation.',
                    409,
                );
            }

            if (! $this->isTerminalStatus($reconciled->status) && (float) $reconciled->amount !== (float) $amount) {
                // The remote order is valid but for an old remaining balance. It
                // will be cancelled by cancelStaleRequests() before a replacement
                // request is created.
                continue;
            }

            if (! $this->isTerminalStatus($reconciled->status)) {
                // SDK v2's PaymentLink lookup intentionally does not expose a QR
                // payload or checkout URL. Never replace a live remote order when
                // those locally persisted fields cannot be recovered.
                throw new PayOSReconciliationException(
                    'The existing payOS payment request is still active, but its checkout details are unavailable.',
                    409,
                );
            }
        }

        // Chỉ sau khi đối soát xong mới hủy các request còn usable nhưng mang số
        // tiền cũ. cancelStaleRequests() cũng xác minh lại khi việc cancel mơ hồ,
        // nhằm tránh tạo order thay thế trong lúc order cũ vẫn có thể nhận tiền.
        $this->cancelStaleRequests($invoice, $amount);

        $request = DB::transaction(function () use ($invoice, $amount) {
            /*
             * Đây là hàng rào chống race condition cuối cùng. Nhiều tab, frontend
             * retry hoặc nhiều HTTP request gần như cùng lúc đều có thể vượt qua
             * fast path bên trên trước khi request đầu tiên kịp lưu QR. lockForUpdate
             * tuần tự hóa quyết định "reuse hay create" theo invoice.
             */
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $amount = $this->remainingAmount($invoice);

            // Trong lúc request hiện tại chờ lock/cancel stale, request khác có thể
            // đã tạo xong QR. Vì vậy bắt buộc check reusable lần nữa bên trong lock.
            $existing = $this->usableRequest($invoice, $amount);
            if ($existing) {
                return $this->withAction($existing, 'reused');
            }

            // Một placeholder mơ hồ còn tồn tại nghĩa là order remote có thể đã
            // được tạo. Dừng lại để reconcile, không phát hành order code thứ hai.
            if ($invoice->payosPaymentRequests()->whereIn('status', ['CREATING', 'CREATION_FAILED', 'RECONCILIATION_REQUIRED'])->exists()) {
                throw new PayOSReconciliationException('A payOS payment request is being reconciled. Please try again.', 409);
            }

            // Ghi placeholder CREATING và order_code khi vẫn đang giữ lock. Nhờ đó
            // mọi request đến sau nhìn thấy dấu vết local trước khi gọi PayOS remote.
            $paymentRequest = $invoice->payosPaymentRequests()->create(['amount' => $amount, 'status' => 'CREATING']);
            $paymentRequest->update(['order_code' => self::ORDER_CODE_OFFSET + $paymentRequest->id]);

            return $paymentRequest;
        });

        // Transaction có thể trả về request do luồng khác vừa tạo xong. Khi action
        // là reused, tuyệt đối không gọi createPaymentLink() bên dưới, nếu không sẽ
        // tạo trùng payOS order dù row lock đã chọn đúng request để dùng lại.
        if ($request->getAttribute('_payos_action') === 'reused') {
            return $request;
        }

        try {
            // Chỉ placeholder CREATING do chính luồng này tạo mới được gửi sang PayOS.
            $response = $this->createPaymentLink($invoice, $request);

            $request->update([
                'payment_link_id' => $response->paymentLinkId,
                'checkout_url' => $response->checkoutUrl,
                'qr_code' => $response->qrCode,
                'amount' => $response->amount,
                'status' => $response->status->value,
                'expired_at' => $response->expiredAt ? Carbon::createFromTimestamp($response->expiredAt) : null,
            ]);

            return $this->withAction($request->fresh(), 'created');
        } catch (\Throwable $exception) {
            // Do not automatically issue another order after an ambiguous SDK failure.
            // A remote request may have been accepted even when this process timed out.
            $request->update(['status' => 'CREATION_FAILED']);
            Log::error('Unable to create payOS payment request.', [
                'invoice_id' => $invoice->id,
                'order_code' => $request->order_code,
                'exception' => $exception->getMessage(),
            ]);

            try {
                $reconciled = $this->reconcilePaymentRequest($request);
                if ($reconciled->isReusableFor((float) $request->amount)) {
                    return $this->withAction($reconciled, 'reconciled');
                }
            } catch (PayOSReconciliationException) {
                // The reconciliation exception below is intentionally user-safe.
            }

            throw new PayOSReconciliationException(
                'Unable to verify the current payOS payment request. Please try again.',
                502,
                $exception,
            );
        }
    }

    /**
     * Đối chiếu một request local với PayOS bằng payment_link_id (ưu tiên) hoặc
     * order_code. Method này chỉ đồng bộ metadata/trạng thái; nó không tự tạo
     * Payment vì trạng thái PAID cần webhook đã được xác thực để chống giả mạo.
     */
    public function reconcilePaymentRequest(PayOSPaymentRequest $request): PayOSPaymentRequest
    {
        try {
            $remote = $this->fetchPaymentLink($request);
        } catch (NotFoundException) {
            // PayOS xác nhận order không tồn tại, nên không còn rủi ro trùng order.
            // Lần bấm tiếp theo có thể tạo request mới với order_code mới.
            $request->update(['status' => 'NOT_FOUND']);

            return $request->fresh();
        } catch (\Throwable $exception) {
            Log::error('Unable to reconcile payOS payment request.', [
                'invoice_id' => $request->invoice_id,
                'order_code' => $request->order_code,
                'exception' => $exception->getMessage(),
            ]);

            throw new PayOSReconciliationException(
                'Unable to verify the current payOS payment request. Please try again.',
                502,
                $exception,
            );
        }

        $status = $remote->status->value;
        // PENDING thì frontend dùng lại QR local. CANCELLED/EXPIRED/NOT_FOUND thì
        // createOrReuse() mới được phép tạo QR thay thế. PAID không tự tạo Payment:
        // chỉ webhook đã verify signature mới được ghi nhận thanh toán.
        $request->update([
            'payment_link_id' => $remote->id,
            'amount' => $remote->amount,
            'status' => $status,
            'cancelled_at' => in_array($status, ['CANCELLED', 'EXPIRED'], true) ? now() : $request->cancelled_at,
        ]);

        return $request->fresh();
    }

    public function messageFor(PayOSPaymentRequest $request): string
    {
        return match ($request->getAttribute('_payos_action')) {
            'reused' => 'Existing payOS payment request reused.',
            'reconciled' => 'payOS payment request reconciled successfully.',
            default => 'payOS payment request ready.',
        };
    }

    public function verifyWebhook(array $payload): WebhookData
    {
        return $this->client()->webhooks->verify($payload);
    }

    public function confirmWebhook(): ConfirmWebhookResponse|array
    {
        return $this->client()->webhooks->confirm($this->configuration('webhook_url'));
    }

    public function processVerifiedWebhook(WebhookData $data): ?Payment
    {
        return DB::transaction(function () use ($data) {
            $request = PayOSPaymentRequest::query()->where('order_code', $data->orderCode)->lockForUpdate()->first();

            if (! $request) {
                Log::warning('payOS webhook has an unknown order code.', ['order_code' => $data->orderCode]);

                return null;
            }

            if ($request->payment_link_id && $request->payment_link_id !== $data->paymentLinkId) {
                Log::warning('payOS webhook payment link does not match its request.', ['order_code' => $data->orderCode]);
                $request->update(['status' => 'RECONCILIATION_REQUIRED']);

                return null;
            }

            if ($data->code !== '00') {
                $request->update(['status' => 'FAILED']);

                return null;
            }

            if ((int) $request->amount !== $data->amount) {
                Log::error('payOS webhook amount does not match the payment request.', [
                    'order_code' => $data->orderCode,
                    'expected_amount' => $request->amount,
                    'received_amount' => $data->amount,
                ]);
                $request->update(['status' => 'RECONCILIATION_REQUIRED']);

                return null;
            }

            $existingPayment = Payment::query()
                ->where('payment_method', 'PAYOS')
                ->where('external_reference', $data->reference)
                ->lockForUpdate()
                ->first();

            if ($existingPayment) {
                $request->update(['status' => 'PAID']);

                return $existingPayment;
            }

            $invoice = Invoice::query()->lockForUpdate()->findOrFail($request->invoice_id);
            $remaining = (float) $invoice->total_amount - (float) $invoice->paid_amount;
            if ($data->amount <= 0 || $data->amount > $remaining) {
                Log::error('payOS webhook would overpay an invoice.', [
                    'invoice_id' => $invoice->id,
                    'order_code' => $data->orderCode,
                    'amount' => $data->amount,
                    'remaining_amount' => $remaining,
                ]);
                $request->update(['status' => 'RECONCILIATION_REQUIRED']);

                return null;
            }

            $payment = $invoice->payments()->create([
                'amount' => $data->amount,
                'payment_method' => 'PAYOS',
                'payment_source' => 'PAYOS_WEBHOOK',
                'external_reference' => $data->reference,
                'reference_code' => $data->reference,
                'paid_at' => $this->paidAt($data->transactionDateTime),
                'note' => 'Thanh toán tự động qua payOS.',
            ]);

            app(InvoicePaymentService::class)->recalculate($invoice);
            $request->update([
                'payment_link_id' => $data->paymentLinkId,
                'status' => 'PAID',
            ]);

            return $payment;
        });
    }

    public function payload(PayOSPaymentRequest $request): array
    {
        return [
            'order_code' => $request->order_code,
            'payment_link_id' => $request->payment_link_id,
            'checkout_url' => $request->checkout_url,
            'qr_code' => $request->qr_code,
            'amount' => (float) $request->amount,
            'status' => $request->status,
            'expired_at' => $request->expired_at,
        ];
    }

    public function markStaleRequests(Invoice $invoice): void
    {
        $remaining = (float) $invoice->total_amount - (float) $invoice->paid_amount;
        $invoice->payosPaymentRequests()
            ->whereIn('status', PayOSPaymentRequest::REUSABLE_STATUSES)
            ->where('amount', '!=', $remaining)
            ->update(['status' => 'STALE']);
    }

    /**
     * A QR encodes one exact remaining amount. When that amount changes, remove
     * every usable QR from API responses immediately. We then try to cancel the
     * matching remote link, but a temporary PayOS error must not undo the invoice
     * edit or leave its old QR reusable locally.
     */
    public function invalidateRequestsForAmountChange(Invoice $invoice, float $oldRemaining, float $newRemaining): void
    {
        if ($oldRemaining === $newRemaining) {
            return;
        }

        $requests = $invoice->payosPaymentRequests()
            ->whereIn('status', PayOSPaymentRequest::REUSABLE_STATUSES)
            ->get();

        foreach ($requests as $request) {
            // STALE removes the request from Invoice::payosPaymentRequest(), so
            // GET /invoices/{id} can no longer expose a QR for the old amount.
            $request->update(['status' => 'STALE']);

            if (blank($request->payment_link_id)) {
                continue;
            }

            try {
                $remote = $this->cancelPaymentLink($request);
                $request->update([
                    'status' => $remote->status->value,
                    'cancelled_at' => now(),
                ]);
            } catch (\Throwable $exception) {
                Log::warning('Unable to cancel stale payOS payment request; it remains locally stale.', [
                    'invoice_id' => $invoice->id,
                    'order_code' => $request->order_code,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function usableRequest(Invoice $invoice, int $amount): ?PayOSPaymentRequest
    {
        // REUSABLE_STATUSES là trạng thái remote/local đã usable thật sự. Ngoài
        // status và số tiền, checkout URL + QR là điều kiện bắt buộc để frontend
        // có thể dùng ngay; các trạng thái mơ hồ không bao giờ đi qua nhánh này.
        return $invoice->payosPaymentRequests()
            ->whereIn('status', PayOSPaymentRequest::REUSABLE_STATUSES)
            ->where('amount', $amount)
            ->whereNotNull('checkout_url')
            ->whereNotNull('qr_code')
            ->latest('id')
            ->first();
    }

    private function cancelStaleRequests(Invoice $invoice, int $amount): void
    {
        $staleRequests = $invoice->payosPaymentRequests()
            ->whereIn('status', array_merge(PayOSPaymentRequest::REUSABLE_STATUSES, ['STALE']))
            ->where('amount', '!=', $amount)
            ->whereNotNull('payment_link_id')
            ->get();

        foreach ($staleRequests as $staleRequest) {
            try {
                $remote = $this->cancelPaymentLink($staleRequest);
                $staleRequest->update([
                    'status' => $remote->status->value,
                    'cancelled_at' => now(),
                ]);
            } catch (\Throwable $exception) {
                Log::error('Unable to cancel stale payOS payment request.', [
                    'order_code' => $staleRequest->order_code,
                    'exception' => $exception->getMessage(),
                ]);

                // A failed cancellation might still have succeeded remotely; query
                // it once before allowing any newer order to be created.
                $reconciled = $this->reconcilePaymentRequest($staleRequest);
                if (! $this->isTerminalStatus($reconciled->status)) {
                    throw new PayOSReconciliationException(
                        'Unable to cancel the previous payOS payment request. Please try again.',
                        502,
                        $exception,
                    );
                }
            }
        }
    }

    private function isTerminalStatus(string $status): bool
    {
        return in_array($status, ['CANCELLED', 'EXPIRED', 'FAILED', 'NOT_FOUND'], true);
    }

    private function withAction(PayOSPaymentRequest $request, string $action): PayOSPaymentRequest
    {
        $request->setAttribute('_payos_action', $action);

        return $request;
    }

    protected function fetchPaymentLink(PayOSPaymentRequest $request): PaymentLink
    {
        /** @var PaymentLink $response */
        $response = $this->client()->paymentRequests->get($request->payment_link_id ?: $request->order_code);

        return $response;
    }

    protected function cancelPaymentLink(PayOSPaymentRequest $request): PaymentLink
    {
        /** @var PaymentLink $response */
        $response = $this->client()->paymentRequests->cancel(
            $request->payment_link_id ?: $request->order_code,
            'Invoice payment amount changed.',
        );

        return $response;
    }

    protected function createPaymentLink(Invoice $invoice, PayOSPaymentRequest $request): CreatePaymentLinkResponse
    {
        /** @var CreatePaymentLinkResponse $response */
        $response = $this->client()->paymentRequests->create(new CreatePaymentLinkRequest(
            $request->order_code,
            (int) $request->amount,
            $this->description($invoice),
            $this->redirectUrl('cancel_url', $invoice->id),
            $this->redirectUrl('return_url', $invoice->id),
        ));

        return $response;
    }

    private function remainingAmount(Invoice $invoice): int
    {
        if (! in_array($invoice->status, ['UNPAID', 'PARTIALLY_PAID'], true) || $invoice->total_amount <= 0) {
            throw ValidationException::withMessages(['status' => 'This invoice cannot receive payOS payments.']);
        }

        $remaining = (float) $invoice->total_amount - (float) $invoice->paid_amount;
        if ($remaining <= 0) {
            throw ValidationException::withMessages(['amount' => 'This invoice has no remaining amount.']);
        }
        if (floor($remaining) !== $remaining) {
            throw ValidationException::withMessages(['amount' => 'payOS payments must use whole VND amounts.']);
        }

        return (int) $remaining;
    }

    protected function client(): PayOS
    {
        $config = config('services.payos');
        if (blank($config['client_id'] ?? null) || blank($config['api_key'] ?? null) || blank($config['checksum_key'] ?? null)) {
            throw new RuntimeException('payOS credentials are not configured.');
        }

        // SDK v2 relies on PSR discovery, whose bundled candidate list does not yet
        // recognize Guzzle 8. Supply the PSR-18/PSR-17 implementations explicitly.
        $factory = new HttpFactory();

        return PayOS::options(new PayOSOptions(
            $config['client_id'],
            $config['api_key'],
            $config['checksum_key'],
            httpClient: new GuzzleClient(),
            requestFactory: $factory,
            streamFactory: $factory,
        ));
    }

    private function redirectUrl(string $key, int $invoiceId): string
    {
        $url = $this->configuration($key);
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'invoice_id='.$invoiceId;
    }

    private function description(Invoice $invoice): string
    {
        return mb_substr('Thanh toan '.$invoice->invoice_code, 0, 25);
    }

    private function configuration(string $key): string
    {
        $value = config('services.payos.'.$key);
        if (blank($value)) {
            throw new RuntimeException('payOS '.$key.' is not configured.');
        }

        return $value;
    }

    private function paidAt(string $value): Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            Log::warning('payOS webhook transaction time could not be parsed.', ['transaction_time' => $value]);

            return now();
        }
    }
}
