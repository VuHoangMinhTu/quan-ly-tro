<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Service;
use App\Models\UtilityMeter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractWizardOpeningAutoMeterSafeV3Test extends TestCase
{
    use RefreshDatabase;

    public function test_draft_creates_missing_meter_from_selected_per_unit_electricity(): void
    {
        $contract = Contract::factory()->create(['status' => 'DRAFT', 'start_date' => null]);
        $room = $contract->room;
        $owner = $room->boardingHouse->landlord->user;
        $service = Service::create([
            'boarding_house_id' => $room->boarding_house_id, 'name' => 'Electricity', 'type' => 'ELECTRICITY',
            'billing_method' => 'PER_UNIT', 'base_price' => 3800, 'unit' => 'kWh', 'is_active' => true,
        ]);
        $room->services()->attach($service, ['is_active' => true]);
        $this->assertDatabaseMissing('utility_meters', ['room_id' => $room->id, 'service_id' => $service->id]);

        $this->withToken($owner->createToken('test')->plainTextToken)
            ->putJson("/api/contracts/{$contract->id}", [
                'status' => 'DRAFT', 'start_date' => '2026-11-04',
                'services' => [['service_id' => $service->id]],
                'utility_openings' => [[
                    'service_id' => $service->id, 'utility_meter_id' => null,
                    'opening_reading' => 125, 'reading_date' => '2026-11-04',
                ]],
            ])->assertOk();

        $meter = UtilityMeter::where('room_id', $room->id)->where('service_id', $service->id)->firstOrFail();
        $this->assertSame('125.00', $meter->initial_reading);
        $this->assertDatabaseHas('contract_utility_openings', [
            'contract_id' => $contract->id, 'service_id' => $service->id,
            'utility_meter_id' => $meter->id, 'opening_reading' => 125,
            'reading_date' => '2026-11-04',
        ]);
    }

    public function test_existing_meter_is_reused_and_not_duplicated(): void
    {
        $contract = Contract::factory()->create(['status' => 'DRAFT', 'start_date' => null]);
        $room = $contract->room;
        $owner = $room->boardingHouse->landlord->user;
        $service = Service::create([
            'boarding_house_id' => $room->boarding_house_id, 'name' => 'Electricity', 'type' => 'ELECTRICITY',
            'billing_method' => 'PER_UNIT', 'base_price' => 3800, 'unit' => 'kWh', 'is_active' => true,
        ]);
        $room->services()->attach($service, ['is_active' => true]);
        $meter = UtilityMeter::create(['room_id' => $room->id, 'service_id' => $service->id, 'initial_reading' => 100, 'is_active' => true]);

        $this->withToken($owner->createToken('test')->plainTextToken)
            ->putJson("/api/contracts/{$contract->id}", [
                'status' => 'DRAFT', 'start_date' => '2026-11-04',
                'services' => [['service_id' => $service->id]],
                'utility_openings' => [[
                    'service_id' => $service->id, 'utility_meter_id' => null,
                    'opening_reading' => 125, 'reading_date' => '2026-11-04',
                ]],
            ])->assertOk();

        $this->assertSame(1, UtilityMeter::where('room_id', $room->id)->where('service_id', $service->id)->count());
        $this->assertDatabaseHas('contract_utility_openings', ['contract_id' => $contract->id, 'utility_meter_id' => $meter->id]);
    }
}
