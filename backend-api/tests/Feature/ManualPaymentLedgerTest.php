<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\User;
use App\Services\InvoicePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualPaymentLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $contract = Contract::factory()->create(['status' => 'ACTIVE']);
        $owner = $contract->room->boardingHouse->landlord->user;
        $this->actingAs($owner, 'sanctum');
        $invoice = $contract->invoices()->create(['room_id' => $contract->room_id, 'billing_period' => '2026-11-01',
            'status' => 'UNPAID', 'subtotal' => 1000000, 'total_amount' => 1000000]);
        $payload = ['amount' => 200000, 'payment_method' => 'CASH', 'paid_at' => '2026-11-08 10:00:00',
            'idempotency_key' => '53f21bdf-c9e1-45b2-a2ce-d35af14c6690'];

        return [$invoice, $payload, $owner];
    }

    public function test_delete_retains_key_and_rejects_same_or_changed_payload_without_restoring_debt(): void
    {
        [$invoice, $payload, $owner] = $this->fixture();
        $url = "/api/invoices/{$invoice->id}/payments";
        $this->postJson($url, $payload)->assertCreated();
        $id = $invoice->payments()->value('id');
        $this->deleteJson("/api/payments/{$id}")->assertOk();

        $this->postJson($url, $payload)->assertStatus(409);
        $this->postJson($url, [...$payload, 'amount' => 300000])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('manual_payment_requests', ['invoice_id' => $invoice->id, 'landlord_id' => $owner->landlord->id,
            'payment_id' => $id, 'state' => 'DELETED', 'deleted_by' => $owner->id]);
        $this->assertSame('0.00', $invoice->fresh()->paid_amount);
        $this->assertSame('UNPAID', $invoice->fresh()->status);
        $this->assertSame('1000000.00', $invoice->fresh()->total_amount);
    }

    public function test_void_receipt_then_cancel_invoice_cannot_be_undone_by_delayed_retry(): void
    {
        [$invoice, $payload] = $this->fixture();
        $url = "/api/invoices/{$invoice->id}/payments";
        $this->postJson($url, $payload)->assertCreated();
        $this->deleteJson('/api/payments/'.$invoice->payments()->value('id'))->assertOk();
        $this->putJson('/api/invoices/'.$invoice->id, ['contract_id' => $invoice->contract_id,
            'billing_period' => '2026-11-01', 'status' => 'CANCELLED', 'discount_amount' => 0])->assertOk();

        $this->postJson($url, $payload)->assertStatus(409);

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('CANCELLED', $invoice->fresh()->status);
        $this->assertSame('0.00', $invoice->fresh()->paid_amount);
    }

    public function test_invoice_hard_delete_cascades_receipt_but_never_deletes_ledger(): void
    {
        [$invoice, $payload] = $this->fixture();
        $this->postJson("/api/invoices/{$invoice->id}/payments", $payload)->assertCreated();
        $invoice->forceDelete();

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('manual_payment_requests', 1);
        $this->postJson("/api/invoices/{$invoice->id}/payments", $payload)->assertNotFound();
    }

    public function test_soft_deleted_invoice_keeps_claim_and_restore_replays_without_new_payment(): void
    {
        [$invoice, $payload] = $this->fixture();
        $url = "/api/invoices/{$invoice->id}/payments";
        $this->postJson($url, $payload)->assertCreated();
        $invoice->delete();
        $this->postJson($url, $payload)->assertNotFound();
        $invoice->restore();

        $this->postJson($url, $payload)->assertCreated();

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('manual_payment_requests', 1);
        $this->assertSame('200000.00', $invoice->fresh()->paid_amount);
    }

    public function test_out_of_band_receipt_delete_is_detected_and_does_not_recreate_receipt(): void
    {
        [$invoice, $payload] = $this->fixture();
        $url = "/api/invoices/{$invoice->id}/payments";
        $this->postJson($url, $payload)->assertCreated();
        $invoice->payments()->delete();

        $this->postJson($url, $payload)->assertStatus(409);

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('manual_payment_requests', ['invoice_id' => $invoice->id, 'state' => 'DELETED']);
        $this->assertSame('0.00', $invoice->fresh()->paid_amount);
    }

    public function test_delete_failure_rolls_back_tombstone_receipt_and_balance_together(): void
    {
        [$invoice, $payload] = $this->fixture();
        $url = "/api/invoices/{$invoice->id}/payments";
        $this->postJson($url, $payload)->assertCreated();
        $id = $invoice->payments()->value('id');
        $this->mock(InvoicePaymentService::class)->shouldReceive('recalculate')->once()->andThrow(new \RuntimeException('Synthetic delete failure'));

        $this->deleteJson("/api/payments/{$id}")->assertStatus(500);

        $this->assertDatabaseHas('payments', ['id' => $id]);
        $this->assertDatabaseHas('manual_payment_requests', ['payment_id' => $id, 'state' => 'APPLIED']);
        $this->assertSame('200000.00', $invoice->fresh()->paid_amount);
    }

    public function test_claim_rolls_back_with_receipt_on_create_failure(): void
    {
        [$invoice, $payload] = $this->fixture();
        $this->mock(InvoicePaymentService::class)->shouldReceive('recalculate')->once()->andThrow(new \RuntimeException('Synthetic create failure'));

        $this->postJson("/api/invoices/{$invoice->id}/payments", $payload)->assertStatus(500);

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('manual_payment_requests', 0);
        $this->assertSame('0.00', $invoice->fresh()->paid_amount);
    }

    public function test_other_owner_and_manager_cannot_create_or_mutate_claims(): void
    {
        [$invoice, $payload] = $this->fixture();
        $this->postJson("/api/invoices/{$invoice->id}/payments", $payload)->assertCreated();
        $manager = User::factory()->create();
        $invoice->room->boardingHouse->managers()->attach($manager);
        foreach ([$manager, User::factory()->create()] as $user) {
            $this->actingAs($user, 'sanctum');
            $this->postJson("/api/invoices/{$invoice->id}/payments", $payload)->assertNotFound();
            $this->deleteJson('/api/payments/'.$invoice->payments()->value('id'))->assertNotFound();
        }
        $this->assertDatabaseCount('manual_payment_requests', 1);
        $this->assertDatabaseHas('manual_payment_requests', ['invoice_id' => $invoice->id, 'state' => 'APPLIED']);
    }

    public function test_legacy_null_key_does_not_get_an_invented_claim(): void
    {
        [$invoice, $payload] = $this->fixture();
        unset($payload['idempotency_key']);
        $this->postJson("/api/invoices/{$invoice->id}/payments", $payload)->assertCreated();
        $this->deleteJson('/api/payments/'.$invoice->payments()->value('id'))->assertOk();

        $this->assertDatabaseCount('manual_payment_requests', 0);
    }
}
