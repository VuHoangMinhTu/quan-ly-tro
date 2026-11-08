<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoicePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class ManualPaymentIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(): Invoice
    {
        $contract = Contract::factory()->create(['status' => 'ACTIVE']);
        $this->actingAs($contract->room->boardingHouse->landlord->user, 'sanctum');

        return $contract->invoices()->create([
            'room_id' => $contract->room_id, 'billing_period' => '2026-11-01',
            'status' => 'UNPAID', 'subtotal' => 1000000, 'total_amount' => 1000000,
            'issued_at' => '2026-11-01',
        ]);
    }

    private function payload(): array
    {
        return [
            'amount' => 200000, 'payment_method' => 'CASH',
            'paid_at' => '2026-11-08 10:00:00', 'reference_code' => 'QA-RECEIPT-01',
            'note' => 'Synthetic QA receipt',
            'idempotency_key' => 'aa3ad841-97e7-4b9b-a29b-9994ad129403',
        ];
    }

    public function test_retrying_a_partial_payment_records_it_once(): void
    {
        $invoice = $this->invoice();
        $url = "/api/invoices/{$invoice->id}/payments";
        $payload = $this->payload();

        $this->postJson($url, $payload)->assertCreated();
        $this->postJson($url, $payload)->assertCreated();

        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('200000.00', $invoice->fresh()->paid_amount);
        $this->assertSame('PARTIALLY_PAID', $invoice->fresh()->status);
    }

    public function test_reusing_a_key_with_different_money_is_rejected_without_another_receipt(): void
    {
        $invoice = $this->invoice();
        $url = "/api/invoices/{$invoice->id}/payments";
        $payload = $this->payload();
        $this->postJson($url, $payload)->assertCreated();

        $this->postJson($url, [...$payload, 'amount' => 300000])
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('200000.00', $invoice->fresh()->paid_amount);
    }

    public function test_retrying_the_final_payment_is_safe_after_the_invoice_becomes_paid(): void
    {
        $invoice = $this->invoice();
        $url = "/api/invoices/{$invoice->id}/payments";
        $payload = [...$this->payload(), 'amount' => 1000000];

        $this->postJson($url, $payload)->assertCreated();
        $this->postJson($url, $payload)->assertCreated();

        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('1000000.00', $invoice->fresh()->paid_amount);
        $this->assertSame('PAID', $invoice->fresh()->status);
    }

    public function test_different_keys_allow_two_distinct_equal_payments_and_cannot_cross_owners(): void
    {
        $invoice = $this->invoice();
        $url = "/api/invoices/{$invoice->id}/payments";
        $payload = $this->payload();
        $this->postJson($url, $payload)->assertCreated();
        $second = [...$payload, 'idempotency_key' => 'c7dd0cf4-c1d7-4bb2-8ca2-aa0ce0b32d52'];

        $this->postJson($url, $second)->assertCreated();
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson($url, $payload)->assertNotFound();

        $this->assertDatabaseCount('payments', 2);
        $this->assertSame('400000.00', $invoice->fresh()->paid_amount);
    }

    public function test_assigned_manager_can_read_but_cannot_record_owner_payments(): void
    {
        $invoice = $this->invoice();
        $manager = User::factory()->create();
        $invoice->room->boardingHouse->managers()->attach($manager);
        $this->actingAs($manager, 'sanctum');

        $this->getJson("/api/invoices/{$invoice->id}")->assertOk()
            ->assertJsonPath('data.can_manage', false)
            ->assertJsonPath('data.total_amount', '1000000.00');
        $this->getJson("/api/invoices/{$invoice->id}/payments")->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/invoices/{$invoice->id}/payments", $this->payload())->assertNotFound();

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('0.00', $invoice->fresh()->paid_amount);
    }

    public function test_two_distinct_bank_transfers_can_share_the_human_reference_label(): void
    {
        $invoice = $this->invoice();
        $payload = [...$this->payload(), 'payment_method' => 'BANK_TRANSFER'];
        $url = "/api/invoices/{$invoice->id}/payments";
        $this->postJson($url, $payload)->assertCreated();
        $this->postJson($url, [...$payload, 'idempotency_key' => 'f347b8d4-b1a7-48f4-adf6-abf9ac224e14'])->assertCreated();
        $this->assertSame(2, $invoice->payments()->whereNull('external_reference')->count());
        $this->assertSame('400000.00', $invoice->fresh()->paid_amount);
    }

    public function test_same_request_key_can_be_used_on_two_different_invoices(): void
    {
        $first = $this->invoice();
        $this->postJson("/api/invoices/{$first->id}/payments", $this->payload())->assertCreated();
        $second = $this->invoice();
        $this->postJson("/api/invoices/{$second->id}/payments", $this->payload())->assertCreated();

        $this->assertSame('200000.00', $first->fresh()->paid_amount);
        $this->assertSame('200000.00', $second->fresh()->paid_amount);
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_error_after_insert_rolls_back_receipt_and_key_then_retry_can_succeed(): void
    {
        $invoice = $this->invoice();
        $this->mock(InvoicePaymentService::class)->shouldReceive('recalculate')->once()
            ->andThrow(new \RuntimeException('Synthetic failure before transaction commit'));
        $url = "/api/invoices/{$invoice->id}/payments";

        $this->postJson($url, $this->payload())->assertStatus(500);

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('0.00', $invoice->fresh()->paid_amount);
        $this->app->forgetInstance(InvoicePaymentService::class);
        $this->postJson($url, $this->payload())->assertCreated();
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('200000.00', $invoice->fresh()->paid_amount);
    }

    public function test_reusing_key_with_changed_method_date_reference_or_note_is_rejected(): void
    {
        $invoice = $this->invoice();
        $url = "/api/invoices/{$invoice->id}/payments";
        $payload = $this->payload();
        $this->postJson($url, $payload)->assertCreated();
        foreach ([['payment_method' => 'BANK_TRANSFER'], ['paid_at' => '2026-11-08 11:00:00'],
            ['reference_code' => 'OTHER-REFERENCE'], ['note' => 'Changed receipt note']] as $change) {
            $this->postJson($url, [...$payload, ...$change])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        }
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('200000.00', $invoice->fresh()->paid_amount);
    }

    public function test_replay_does_not_undo_a_later_manual_edit_of_the_receipt(): void
    {
        $invoice = $this->invoice();
        $url = "/api/invoices/{$invoice->id}/payments";
        $payload = $this->payload();
        $this->postJson($url, $payload)->assertCreated();
        $payment = $invoice->payments()->firstOrFail();
        $this->putJson("/api/payments/{$payment->id}", [...$payload, 'amount' => 300000])->assertOk();

        $this->postJson($url, $payload)->assertCreated();

        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('300000.00', $invoice->fresh()->paid_amount);
    }

    #[Group('payment-idempotency-blocker')]
    public function test_retry_does_not_recreate_a_keyed_receipt_that_the_owner_deleted(): void
    {
        $invoice = $this->invoice();
        $url = "/api/invoices/{$invoice->id}/payments";
        $this->postJson($url, $this->payload())->assertCreated();
        $payment = $invoice->payments()->firstOrFail();
        $this->deleteJson("/api/payments/{$payment->id}")->assertOk();
        $this->assertSame('0.00', $invoice->fresh()->paid_amount);

        $this->postJson($url, $this->payload());

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('0.00', $invoice->fresh()->paid_amount);
    }
}
