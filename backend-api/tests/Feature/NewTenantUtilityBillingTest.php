<?php

namespace Tests\Feature;

use App\Models\Contract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class NewTenantUtilityBillingTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->travelTo(now()->setDate(2026, 10, 31)->startOfDay());
        $new = Contract::factory()->create(['start_date' => '2026-10-23', 'end_date' => '2027-09-30', 'monthly_rent' => 3000000]);
        $owner = $new->room->boardingHouse->landlord->user;
        $this->actingAs($owner, 'sanctum');
        $service = $new->room->boardingHouse->services()->create(['name' => 'Điện', 'type' => 'ELECTRICITY', 'billing_method' => 'PER_UNIT', 'base_price' => 3800, 'unit' => 'kWh', 'is_active' => true]);
        $new->room->services()->attach($service, ['is_active' => true]);
        $meter = $new->room->utilityMeters()->create(['service_id' => $service->id, 'initial_reading' => 100, 'is_active' => true]);
        $meter->readings()->create(['reading_date' => '2026-09-01', 'reading_value' => 100]);
        $old = Contract::factory()->create(['room_id' => $new->room_id, 'status' => 'ACTIVE', 'start_date' => '2026-09-01', 'end_date' => '2027-09-30']);
        $old->occupants()->create(['room_id' => $old->room_id, 'tenant_id' => $old->tenant_id, 'is_primary' => true, 'move_in_date' => '2026-09-01', 'status' => 'ACTIVE']);
        $old->utilityOpenings()->create(['utility_meter_id' => $meter->id, 'service_id' => $service->id, 'service_name' => 'Điện', 'opening_reading' => 100, 'reading_date' => '2026-09-01']);
        $this->putJson("/api/contracts/{$old->id}/status", ['status' => 'TERMINATED', 'move_out_date' => '2026-10-22'])->assertOk();
        $new->handoverReports()->create(['type' => 'CHECK_IN', 'status' => 'CONFIRMED', 'items' => [['name' => 'Đồ bàn giao', 'quantity' => 1]], 'confirmed_at' => now()]);

        return [$new, $old, $owner, $service, $meter];
    }

    private function activate(Contract $contract, int $ownerId, array $openings, array $services): TestResponse
    {
        return $this->putJson("/api/contracts/{$contract->id}", ['status' => 'ACTIVE', 'lessor_representative_user_id' => $ownerId, 'utility_openings' => $openings, 'services' => $services]);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_new_tenant_pays_only_195_minus_175_for_both_contract_and_live_room_service(bool $agreed): void
    {
        [$new, $old, $owner, $service, $meter] = $this->fixture();
        $opening = ['utility_meter_id' => $meter->id, 'opening_reading' => 175, 'reading_date' => '2026-10-23'];
        $this->activate($new, $owner->id, [$opening], $agreed ? [['service_id' => $service->id]] : [])->assertOk();
        $meter->readings()->create(['reading_date' => '2026-10-31', 'reading_value' => 195]);
        $this->postJson("/api/rooms/{$new->room_id}/invoices", ['contract_id' => $new->id, 'billing_period' => '2026-10-01', 'status' => 'DRAFT'])->assertCreated();
        $invoice = $new->invoices()->firstOrFail();

        $this->postJson("/api/invoices/{$invoice->id}/generate")->assertOk();

        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'ELECTRICITY', 'quantity' => 20, 'unit_price' => 3800, 'amount' => 76000]);
        $this->assertDatabaseMissing('invoice_items', ['invoice_id' => $invoice->id, 'quantity' => 95]);
        $this->assertDatabaseHas('contract_utility_openings', ['contract_id' => $new->id, 'opening_reading' => 175, 'reading_date' => '2026-10-23']);
        $this->assertDatabaseHas('contract_utility_openings', ['contract_id' => $old->id, 'opening_reading' => 100]);
        $this->assertDatabaseCount('utility_readings', 2);
        $this->assertDatabaseHas('utility_meters', ['id' => $meter->id, 'initial_reading' => 100]);
        $this->assertDatabaseCount('payments', 0);
    }

    #[TestWith([90, '2026-10-23'])]
    #[TestWith([175, '2026-09-01'])]
    public function test_invalid_handover_reading_cannot_activate_or_rewrite_old_history(int $value, string $date): void
    {
        [$new, $old, $owner, $service, $meter] = $this->fixture();
        $this->activate($new, $owner->id, [['utility_meter_id' => $meter->id, 'opening_reading' => $value, 'reading_date' => $date]], [['service_id' => $service->id]])->assertUnprocessable();
        $this->assertDatabaseHas('contracts', ['id' => $new->id, 'status' => 'DRAFT']);
        $this->assertDatabaseHas('contract_utility_openings', ['contract_id' => $old->id, 'opening_reading' => 100]);
        $this->assertSame(0, $new->occupants()->count());
    }

    #[TestWith(['2026-11-01', '2027-09-30'])]
    #[TestWith(['2026-10-31', '2026-10-28'])]
    public function test_missing_closing_does_not_borrow_reading_outside_contract_period_or_delete_existing_items(string $readingDate, string $contractEnd): void
    {
        [$new, , $owner, $service, $meter] = $this->fixture();
        $new->update(['end_date' => $contractEnd]);
        $this->activate($new, $owner->id, [['utility_meter_id' => $meter->id, 'opening_reading' => 175, 'reading_date' => '2026-10-23']], [['service_id' => $service->id]])->assertOk();
        $meter->readings()->create(['reading_date' => $readingDate, 'reading_value' => 195]);
        $invoice = $new->invoices()->create(['room_id' => $new->room_id, 'billing_period' => '2026-10-01', 'status' => 'DRAFT', 'discount_amount' => 0]);
        $item = $invoice->items()->create(['type' => 'RENT', 'description' => 'Khoản đã ghi', 'quantity' => 1, 'unit_price' => 3000000, 'amount' => 3000000, 'source' => 'AUTO']);
        $invoice->refresh()->recalculateTotals();
        $before = $invoice->fresh()->getAttributes();

        $this->postJson("/api/invoices/{$invoice->id}/generate")->assertUnprocessable()->assertJsonValidationErrors('reading_date');

        $this->assertModelExists($item);
        $this->assertSame($before, $invoice->fresh()->getAttributes());
    }

    public function test_missing_handover_cannot_activate_new_tenancy_or_generate_from_old_meter_baseline(): void
    {
        [$new, , $owner, $service] = $this->fixture();
        $this->activate($new, $owner->id, [], [['service_id' => $service->id]])->assertUnprocessable()->assertJsonValidationErrors('utility_openings');
        $this->assertDatabaseHas('contracts', ['id' => $new->id, 'status' => 'DRAFT']);
    }
}
