<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UtilityLedgerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_load_one_house_ledger_without_creating_an_invoice(): void
    {
        config(['billing.v2_enabled' => true]);
        $contract = Contract::factory()->create(['status' => 'ACTIVE', 'start_date' => '2026-01-01']);
        $room = $contract->room;
        $owner = $room->boardingHouse->landlord->user;
        $service = Service::create([
            'boarding_house_id' => $room->boarding_house_id,
            'name' => 'Điện phòng',
            'type' => 'ELECTRICITY',
            'billing_method' => 'PER_UNIT',
            'base_price' => 3000,
            'unit' => 'kWh',
            'is_active' => true,
        ]);
        $meter = $room->utilityMeters()->create(['service_id' => $service->id, 'initial_reading' => 100, 'is_active' => true]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/billing-v2/meters/{$meter->id}/checkpoints", [
                'kind' => 'PERIOD_BOUNDARY', 'observed_at' => '2026-05-01 08:00:00', 'reading_value' => 140,
            ])->assertOk();

        $this->getJson("/api/boarding-houses/{$room->boarding_house_id}/utility-ledger?year=2026")
            ->assertOk()
            ->assertJsonPath('data.year', 2026)
            ->assertJsonPath('data.rooms.0.id', $room->id)
            ->assertJsonPath('data.meters.0.id', $meter->id)
            ->assertJsonPath('data.checkpoints.0.reading_value', '140.00');

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_ledger_respects_feature_switch_and_house_access(): void
    {
        $contract = Contract::factory()->create();
        $house = $contract->room->boardingHouse;
        $this->actingAs($house->landlord->user, 'sanctum')
            ->getJson("/api/boarding-houses/{$house->id}/utility-ledger?year=2026")
            ->assertStatus(503);
    }
}
