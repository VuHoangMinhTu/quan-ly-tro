<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UtilityLedgerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_invoice_can_be_created(): void
    {
        $room = Contract::factory()->create()->room;

        $invoice = $room->invoices()->create([
            'invoice_code' => 'TEST-UTILITY-001',
            'billing_period' => '2026-10-01',
            'status' => 'DRAFT',
            'subtotal' => 0,
            'total_amount' => 0,
            'paid_amount' => 0,
        ]);

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'room_id' => $room->id,
        ]);
    }

    public function test_explicit_periods_share_a_checkpoint_and_survive_correction(): void
    {
        config(['billing.v2_enabled' => true]);
        $room = Contract::factory()->create()->room;
        $service = $room->boardingHouse->services()->create([
            'name' => 'Điện', 'type' => 'ELECTRICITY', 'billing_method' => 'PER_UNIT',
        ]);
        $meter = $room->utilityMeters()->create(['service_id' => $service->id]);
        $this->actingAs($room->boardingHouse->landlord->user, 'sanctum');
        $url = "/api/billing-v2/meters/{$meter->id}/period-boundaries";
        $first = [
            'billing_period' => '2026-05-01', 'start_observed_at' => '2026-04-19 08:00:00',
            'end_observed_at' => '2026-06-02 09:00:00', 'start_reading_value' => 100, 'end_reading_value' => 140,
        ];
        $pair = $this->postJson($url, $first)->assertOk()->json('data');
        $this->postJson($url, $first)->assertOk()->assertJsonPath('data.end_checkpoint_id', $pair['end_checkpoint_id']);
        $this->postJson($url, [
            ...$first, 'billing_period' => '2026-06-01',
            'start_observed_at' => $first['end_observed_at'], 'start_reading_value' => 140,
            'end_observed_at' => '2026-07-03 10:00:00', 'end_reading_value' => 170,
        ])->assertOk()->assertJsonPath('data.start_checkpoint_id', $pair['end_checkpoint_id']);
        $this->postJson($url, [...$first, 'end_observed_at' => '2026-06-03 09:00:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('billing_period');
        $this->assertDatabaseCount('utility_checkpoints', 3);

        $corrected = $this->postJson($url.'/correct', [
            ...$first, ...$pair, 'end_reading_value' => 145,
            'reason' => 'Đối chiếu ảnh chụp công tơ',
            'request_key' => '9561576e-f910-49b0-888c-c6ac2aa3663f',
        ])->assertOk()->json('data.end_checkpoint_id');
        $this->assertDatabaseHas('utility_meter_period_boundaries', [
            'billing_period' => '2026-06-01', 'start_checkpoint_id' => $corrected,
        ]);
        $this->getJson("/api/boarding-houses/{$room->boarding_house_id}/utility-ledger?year=2026")
            ->assertOk()->assertJsonCount(2, 'data.boundaries')->assertJsonCount(0, 'data.periods');
        $this->assertDatabaseCount('utility_checkpoint_corrections', 1);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('payments', 0);
    }

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

        $response = $this->getJson("/api/boarding-houses/{$room->boarding_house_id}/utility-ledger?year=2026")
            ->assertOk()
            ->assertJsonPath('data.year', 2026)
            ->assertJsonPath('data.can_record', true)
            ->assertJsonPath('data.rooms.0.id', $room->id)
            ->assertJsonPath('data.meters.0.id', $meter->id)
            ->assertJsonCount(0, 'data.periods');
        $this->assertSame(140.0, (float) $response->json('data.checkpoints.0.reading_value'));

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_ledger_respects_feature_switch_and_house_access(): void
    {
        config(['billing.v2_enabled' => false]);
        $contract = Contract::factory()->create();
        $house = $contract->room->boardingHouse;
        $this->actingAs($house->landlord->user, 'sanctum')
            ->getJson("/api/boarding-houses/{$house->id}/utility-ledger?year=2026")
            ->assertStatus(503);
        config(['billing.v2_enabled' => true]);
        $manager = User::factory()->create();
        $house->managers()->attach($manager);
        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/boarding-houses/{$house->id}/utility-ledger?year=2026")
            ->assertOk()->assertJsonPath('data.can_record', false);
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/boarding-houses/{$house->id}/utility-ledger?year=2026")
            ->assertNotFound();
    }
}
