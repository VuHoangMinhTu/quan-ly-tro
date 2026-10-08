<?php

namespace Tests\Feature;

use App\Models\BoardingHouse;
use App\Models\Invoice;
use App\Models\Landlord;
use App\Models\PayOSPaymentRequest;
use App\Models\Payment;
use App\Models\Room;
use App\Models\User;
use App\Services\PayOSService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PayOS\Exceptions\WebhookException;
use PayOS\Exceptions\NotFoundException;
use PayOS\Models\V2\PaymentRequests\CreatePaymentLinkResponse;
use PayOS\Models\V2\PaymentRequests\PaymentLink;
use PayOS\Models\V2\PaymentRequests\PaymentLinkStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PayOS\Models\Webhooks\WebhookData;
use Tests\TestCase;

class PayOSApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_a_payos_payment_request_for_the_remaining_amount(): void
    {
        [$user, $invoice] = $this->ownerAndInvoice(5_000_000, 2_000_000);
        $paymentRequest = $this->paymentRequest($invoice, 3_000_000);
        $service = Mockery::mock(PayOSService::class);
        $service->shouldReceive('createOrReuse')->once()->andReturn($paymentRequest);
        $service->shouldReceive('payload')->once()->andReturn([
            'order_code' => $paymentRequest->order_code,
            'checkout_url' => 'https://pay.example/checkout',
            'qr_code' => '000201010212',
            'amount' => 3_000_000,
            'status' => 'PENDING',
        ]);
        $service->shouldReceive('messageFor')->once()->andReturn('payOS payment request ready.');
        $this->app->instance(PayOSService::class, $service);

        $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson("/api/invoices/{$invoice->id}/payos")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.payment_request.amount', 3_000_000)
            ->assertJsonPath('data.payment_request.checkout_url', 'https://pay.example/checkout');
    }

    public function test_reconciliation_recovers_a_pending_request_without_creating_another_order(): void
    {
        [, $invoice] = $this->ownerAndInvoice(3_000_000);
        $request = PayOSPaymentRequest::create([
            'invoice_id' => $invoice->id,
            'order_code' => 9_000_000_111,
            'payment_link_id' => 'payos-link-reconcile',
            'checkout_url' => 'https://pay.example/checkout',
            'qr_code' => '000201010212',
            'amount' => 3_000_000,
            'status' => 'RECONCILIATION_REQUIRED',
        ]);
        $service = $this->reconciliationService();
        $service->shouldReceive('fetchPaymentLink')->once()->andReturn($this->remoteLink($request, PaymentLinkStatus::PENDING));
        $service->shouldReceive('createPaymentLink')->never();

        $result = $service->createOrReuse($invoice);

        $this->assertSame($request->id, $result->id);
        $this->assertSame('PENDING', $request->fresh()->status);
        $this->assertSame('reconciled', $result->getAttribute('_payos_action'));
        $this->assertDatabaseCount('payos_payment_requests', 1);
    }

    #[DataProvider('terminalRemoteStatuses')]
    public function test_terminal_remote_request_allows_a_new_order(string $status): void
    {
        [, $invoice] = $this->ownerAndInvoice(3_000_000);
        $request = PayOSPaymentRequest::create([
            'invoice_id' => $invoice->id,
            'order_code' => 9_000_000_112,
            'payment_link_id' => 'payos-link-terminal',
            'amount' => 3_000_000,
            'status' => 'RECONCILIATION_REQUIRED',
        ]);
        $service = $this->reconciliationService();
        $service->shouldReceive('fetchPaymentLink')->once()->andReturn($this->remoteLink($request, PaymentLinkStatus::from($status)));
        $service->shouldReceive('createPaymentLink')->once()->andReturn($this->createdLink(9_000_000_002, 3_000_000));

        $result = $service->createOrReuse($invoice);

        $this->assertNotSame($request->id, $result->id);
        $this->assertSame($status, $request->fresh()->status);
        $this->assertDatabaseCount('payos_payment_requests', 2);
    }

    public static function terminalRemoteStatuses(): array
    {
        return [
            'cancelled' => [PaymentLinkStatus::CANCELLED->value],
            'expired' => [PaymentLinkStatus::EXPIRED->value],
        ];
    }

    public function test_not_found_remote_request_allows_a_new_order(): void
    {
        [, $invoice] = $this->ownerAndInvoice(3_000_000);
        $request = PayOSPaymentRequest::create([
            'invoice_id' => $invoice->id,
            'order_code' => 9_000_000_113,
            'amount' => 3_000_000,
            'status' => 'CREATION_FAILED',
        ]);
        $service = $this->reconciliationService();
        $service->shouldReceive('fetchPaymentLink')->once()->andThrow(new NotFoundException(404, ['code' => '404'], 'Not found', []));
        $service->shouldReceive('createPaymentLink')->once()->andReturn($this->createdLink(9_000_000_002, 3_000_000));

        $service->createOrReuse($invoice);

        $this->assertSame('NOT_FOUND', $request->fresh()->status);
        $this->assertDatabaseCount('payos_payment_requests', 2);
    }

    public function test_timeout_during_reconciliation_does_not_create_another_order(): void
    {
        [, $invoice] = $this->ownerAndInvoice(3_000_000);
        $request = PayOSPaymentRequest::create([
            'invoice_id' => $invoice->id,
            'order_code' => 9_000_000_114,
            'amount' => 3_000_000,
            'status' => 'RECONCILIATION_REQUIRED',
        ]);
        $service = $this->reconciliationService();
        $service->shouldReceive('fetchPaymentLink')->once()->andThrow(new \RuntimeException('Timed out'));
        $service->shouldReceive('createPaymentLink')->never();

        $this->expectException(\App\Exceptions\PayOSReconciliationException::class);
        $this->expectExceptionMessage('Unable to verify the current payOS payment request. Please try again.');
        $service->createOrReuse($invoice);

        $this->assertSame('RECONCILIATION_REQUIRED', $request->fresh()->status);
        $this->assertDatabaseCount('payos_payment_requests', 1);
    }

    public function test_pending_remote_request_with_an_old_amount_is_cancelled_before_a_replacement_is_created(): void
    {
        [, $invoice] = $this->ownerAndInvoice(2_000_000);
        $request = PayOSPaymentRequest::create([
            'invoice_id' => $invoice->id,
            'order_code' => 9_000_000_115,
            'payment_link_id' => 'payos-link-stale',
            'amount' => 3_000_000,
            'status' => 'RECONCILIATION_REQUIRED',
        ]);
        $service = $this->reconciliationService();
        $service->shouldReceive('fetchPaymentLink')->once()->andReturn($this->remoteLink($request, PaymentLinkStatus::PENDING));
        $service->shouldReceive('cancelPaymentLink')->once()->andReturn($this->remoteLink($request, PaymentLinkStatus::CANCELLED));
        $service->shouldReceive('createPaymentLink')->once()->andReturn($this->createdLink(9_000_000_002, 2_000_000));

        $result = $service->createOrReuse($invoice);

        $this->assertNotSame($request->id, $result->id);
        $this->assertSame('CANCELLED', $request->fresh()->status);
        $this->assertSame('2000000.00', $result->amount);
    }

    public function test_another_landlord_cannot_create_a_payos_request_for_an_invoice_they_do_not_own(): void
    {
        [, $invoice] = $this->ownerAndInvoice();
        $other = $this->owner();

        $this->withToken($other->createToken('test')->plainTextToken)
            ->postJson("/api/invoices/{$invoice->id}/payos")
            ->assertNotFound();
    }

    public function test_paid_invoice_cannot_create_a_payos_request(): void
    {
        [$user, $invoice] = $this->ownerAndInvoice(100_000, 100_000, 'PAID');

        $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson("/api/invoices/{$invoice->id}/payos")
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_reuses_the_existing_pending_request_when_remaining_amount_is_unchanged(): void
    {
        [, $invoice] = $this->ownerAndInvoice(3_000_000);
        $request = $this->paymentRequest($invoice, 3_000_000);

        $result = app(PayOSService::class)->createOrReuse($invoice);

        $this->assertSame($request->id, $result->id);
        $this->assertDatabaseCount('payos_payment_requests', 1);
    }

    public function test_invoice_detail_returns_the_current_usable_payos_request(): void
    {
        [$user, $invoice] = $this->ownerAndInvoice(3_000_000);
        $request = $this->paymentRequest($invoice, 3_000_000);

        $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson("/api/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.payos_payment_request.id', $request->id)
            ->assertJsonPath('data.payos_payment_request.order_code', $request->order_code)
            ->assertJsonPath('data.payos_payment_request.qr_code', '000201010212');
    }

    public function test_partial_cash_payment_marks_an_outdated_payos_request_as_stale(): void
    {
        [$user, $invoice] = $this->ownerAndInvoice(3_000_000);
        $request = $this->paymentRequest($invoice, 3_000_000);

        $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson("/api/invoices/{$invoice->id}/payments", [
                'amount' => 1_000_000,
                'payment_method' => 'CASH',
                'paid_at' => '2026-09-29 10:00:00',
            ])->assertCreated();

        $this->assertSame('STALE', $request->fresh()->status);
    }

    public function test_verified_webhook_creates_one_payos_payment_and_recalculates_invoice(): void
    {
        [, $invoice] = $this->ownerAndInvoice(3_000_000);
        $request = $this->paymentRequest($invoice, 3_000_000);
        $data = $this->webhookData($request, 3_000_000);
        $service = Mockery::mock(PayOSService::class)->makePartial();
        $service->shouldReceive('verifyWebhook')->twice()->andReturn($data);
        $this->app->instance(PayOSService::class, $service);

        $this->postJson('/api/webhooks/payos', ['code' => '00'])->assertOk()->assertJsonPath('success', true);
        $this->postJson('/api/webhooks/payos', ['code' => '00'])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'amount' => 3_000_000,
            'payment_method' => 'PAYOS',
            'payment_source' => 'PAYOS_WEBHOOK',
            'external_reference' => 'PAYOS-REFERENCE-1',
        ]);
        $this->assertSame('PAID', $invoice->fresh()->status);
        $this->assertSame('3000000.00', $invoice->fresh()->paid_amount);
        $this->assertSame('PAID', $request->fresh()->status);
    }

    public function test_invalid_payos_webhook_does_not_create_a_payment(): void
    {
        $service = Mockery::mock(PayOSService::class);
        $service->shouldReceive('verifyWebhook')->once()->andThrow(new WebhookException('Invalid signature'));
        $this->app->instance(PayOSService::class, $service);

        $this->postJson('/api/webhooks/payos', ['code' => 'bad'])
            ->assertStatus(400)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_unknown_order_code_is_acknowledged_without_creating_a_payment(): void
    {
        $data = new WebhookData(9_999_999_999, 100_000, 'Invoice', '123', 'UNKNOWN', '2026-09-29T10:00:00+07:00', 'VND', 'link', '00', 'success');
        $service = Mockery::mock(PayOSService::class)->makePartial();
        $service->shouldReceive('verifyWebhook')->once()->andReturn($data);
        $this->app->instance(PayOSService::class, $service);

        $this->postJson('/api/webhooks/payos', ['code' => '00'])->assertOk();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_manual_cash_payment_flow_still_works(): void
    {
        [$user, $invoice] = $this->ownerAndInvoice(500_000);

        $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson("/api/invoices/{$invoice->id}/payments", [
                'amount' => 500_000,
                'payment_method' => 'CASH',
                'paid_at' => '2026-09-29 10:00:00',
            ])
            ->assertCreated()
            ->assertJsonPath('data', null);

        $this->assertDatabaseHas('payments', ['invoice_id' => $invoice->id, 'payment_method' => 'CASH', 'payment_source' => 'MANUAL']);
    }

    public function test_payos_payment_cannot_be_edited_or_deleted_manually(): void
    {
        [$user, $invoice] = $this->ownerAndInvoice(100_000, 100_000, 'PAID');
        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 100_000,
            'payment_method' => 'PAYOS',
            'payment_source' => 'PAYOS_WEBHOOK',
            'external_reference' => 'PAYOS-LOCKED',
            'paid_at' => now(),
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->putJson("/api/payments/{$payment->id}", [
            'amount' => 1,
            'payment_method' => 'CASH',
            'paid_at' => '2026-09-29 10:00:00',
        ])->assertUnprocessable();
        $this->withToken($token)->deleteJson("/api/payments/{$payment->id}")->assertUnprocessable();
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'payment_method' => 'PAYOS']);
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        Landlord::create(['user_id' => $user->id, 'full_name' => $user->name, 'phone' => '0900000000']);

        return $user;
    }

    private function ownerAndInvoice(int $total = 1_000_000, int $paid = 0, string $status = 'UNPAID'): array
    {
        $user = $this->owner();
        $boardingHouse = BoardingHouse::create(['landlord_id' => $user->landlord->id, 'name' => 'House', 'address' => 'Address']);
        $room = Room::create(['boarding_house_id' => $boardingHouse->id, 'room_code' => 'P'.Room::query()->count(), 'monthly_rent' => 1, 'status' => 'AVAILABLE']);
        $invoice = Invoice::create([
            'room_id' => $room->id,
            'invoice_code' => 'INV-'.Invoice::query()->count(),
            'billing_period' => '2026-09-01',
            'subtotal' => $total,
            'discount_amount' => 0,
            'total_amount' => $total,
            'paid_amount' => $paid,
            'status' => $status,
        ]);

        return [$user, $invoice];
    }

    private function paymentRequest(Invoice $invoice, int $amount): PayOSPaymentRequest
    {
        return PayOSPaymentRequest::create([
            'invoice_id' => $invoice->id,
            'order_code' => 9_000_000_000 + PayOSPaymentRequest::query()->count() + 1,
            'payment_link_id' => 'payos-link-'.PayOSPaymentRequest::query()->count(),
            'checkout_url' => 'https://pay.example/checkout',
            'qr_code' => '000201010212',
            'amount' => $amount,
            'status' => 'PENDING',
        ]);
    }

    private function webhookData(PayOSPaymentRequest $request, int $amount): WebhookData
    {
        return new WebhookData($request->order_code, $amount, 'Invoice', '123', 'PAYOS-REFERENCE-1', '2026-09-29T10:00:00+07:00', 'VND', $request->payment_link_id, '00', 'success');
    }

    private function reconciliationService(): PayOSService
    {
        return Mockery::mock(PayOSService::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
    }

    private function remoteLink(PayOSPaymentRequest $request, PaymentLinkStatus $status, ?int $amount = null): PaymentLink
    {
        return new PaymentLink(
            $request->payment_link_id ?: 'payos-link-'.$request->id,
            $request->order_code,
            $amount ?? (int) $request->amount,
            0,
            $amount ?? (int) $request->amount,
            $status,
            '2026-09-29T10:00:00+07:00',
            [],
        );
    }

    private function createdLink(int $orderCode, int $amount): CreatePaymentLinkResponse
    {
        return new CreatePaymentLinkResponse(
            '970422',
            '123456789',
            'Test account',
            $amount,
            'Invoice',
            $orderCode,
            'VND',
            'new-pay-link',
            PaymentLinkStatus::PENDING,
            'https://pay.example/new-checkout',
            '000201010212',
        );
    }
}
