<?php

namespace Tests\Feature;

use App\Models\Contract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BillingV2QaLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $status = 'ACTIVE'): array
    {
        Http::preventStrayRequests();
        $this->travelTo(now()->setDate(2026, 11, 8)->startOfDay());
        $contract = Contract::factory()->create([
            'status' => $status, 'start_date' => '2026-10-03',
            'end_date' => null, 'monthly_rent' => 3000000, 'service_terms_version' => 1,
        ]);
        $room = $contract->room;
        $this->actingAs($room->boardingHouse->landlord->user, 'sanctum');
        $contract->handoverReports()->create([
            'type' => 'CHECK_IN', 'status' => 'CONFIRMED', 'items' => [],
            'confirmed_at' => '2026-10-03 00:00:00',
        ]);
        $meters = [];
        foreach ([
            ['ELECTRICITY', 3000, 'kWh', 100, 160],
            ['WATER', 15000, 'm3', 10, 15],
        ] as [$type, $price, $unit, $start, $end]) {
            $service = $room->boardingHouse->services()->create([
                'name' => 'QA '.$type, 'type' => $type, 'billing_method' => 'PER_UNIT',
                'base_price' => $price, 'unit' => $unit, 'is_active' => true,
            ]);
            $room->services()->attach($service, ['is_active' => true]);
            $contract->agreedServices()->create([
                'service_id' => $service->id, 'service_name' => $service->name,
                'service_type' => $type, 'billing_method' => 'PER_UNIT',
                'base_price' => $price, 'unit' => $unit, 'effective_from' => '2026-10-01',
                'is_active' => true, 'pricing_snapshot' => [],
            ]);
            $meter = $room->utilityMeters()->create([
                'service_id' => $service->id, 'initial_reading' => $start, 'is_active' => true,
            ]);
            $this->postJson("/api/billing-v2/meters/{$meter->id}/checkpoints", [
                'kind' => 'CHECK_IN', 'contract_id' => $contract->id,
                'observed_at' => '2026-10-03 00:00:00', 'reading_value' => $start,
            ])->assertOk();
            $this->postJson("/api/billing-v2/meters/{$meter->id}/period-boundaries", [
                'start_observed_at' => '2026-11-03 08:00:00',
                'end_observed_at' => '2026-12-03 08:00:00',
                'start_reading_value' => $start, 'end_reading_value' => $end,
            ])->assertOk();
            $meters[] = $meter;
        }

        return [$contract, $room, $meters];
    }

    public function test_active_contract_flexible_meter_cycle_draft_issue_and_payment_have_exact_totals(): void
    {
        [$contract, $room] = $this->fixture();
        $payload = [
            'billing_period' => '2026-11-01', 'usage_start_at' => '2026-11-03 08:00:00',
            'usage_end_at' => '2026-12-03 08:00:00',
        ];
        $url = "/api/billing-v2/rooms/{$room->id}/monthly-invoices";

        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.0.status', 'CREATED');
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.0.status', 'EXISTING');

        $invoice = $room->invoices()->firstOrFail();
        $this->assertDatabaseCount('invoices', 1);
        $this->assertSame('3255000.00', $invoice->total_amount);
        $this->assertSame('180000.00', (string) $invoice->items()->where('type', 'ELECTRICITY')->sum('amount'));
        $this->assertSame('75000.00', (string) $invoice->items()->where('type', 'WATER')->sum('amount'));
        $this->assertSame('3000000.00', (string) $invoice->items()->where('type', 'RENT')->sum('amount'));
        $this->assertSame('DRAFT', $invoice->status);
        $this->assertDatabaseHas('utility_billing_periods', ['quantity' => 60, 'tariff_total' => 180000]);
        $this->assertDatabaseHas('utility_billing_periods', ['quantity' => 5, 'tariff_total' => 75000]);

        $this->postJson("/api/billing-v2/invoices/{$invoice->id}/issue", [])->assertOk();
        $this->assertSame('UNPAID', $invoice->fresh()->status);
        $payment = [
            'amount' => 1000000, 'payment_method' => 'CASH', 'paid_at' => '2026-11-08 10:00:00',
            'idempotency_key' => '0335cb5e-a50d-429d-9e47-26bdb2ee1403',
        ];
        $this->postJson("/api/invoices/{$invoice->id}/payments", $payment)->assertCreated();
        $this->postJson("/api/invoices/{$invoice->id}/payments", $payment)->assertCreated();
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('1000000.00', $invoice->fresh()->paid_amount);
        $this->assertSame('PARTIALLY_PAID', $invoice->fresh()->status);
        $this->postJson("/api/invoices/{$invoice->id}/payments", [
            ...$payment, 'amount' => 2255000, 'idempotency_key' => '5264de62-4a96-41a2-a077-fc29a666d8fa',
        ])->assertCreated();
        $this->assertSame('3255000.00', $invoice->fresh()->paid_amount);
        $this->assertSame('PAID', $invoice->fresh()->status);
        $this->assertSame($contract->id, $invoice->contract_id);
    }

    public function test_draft_contract_does_not_create_monthly_or_utility_invoices(): void
    {
        $contract = Contract::factory()->create(['status' => 'DRAFT', 'start_date' => '2026-10-01', 'end_date' => null]);
        $room = $contract->room;
        $this->actingAs($room->boardingHouse->landlord->user, 'sanctum');

        $this->postJson("/api/billing-v2/rooms/{$room->id}/monthly-invoices", [
            'billing_period' => '2026-11-01', 'usage_start_at' => '2026-11-03 08:00:00',
            'usage_end_at' => '2026-12-03 08:00:00',
        ])->assertOk()->assertJsonPath('data.0.status', 'NO_ELIGIBLE_CONTRACT');
        $this->putJson("/api/contracts/{$contract->id}/status", ['status' => 'PENDING'])
            ->assertUnprocessable();

        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame('DRAFT', $contract->fresh()->status);
        $this->assertSame(0, DB::table('invoice_obligations')->count());
    }
}
