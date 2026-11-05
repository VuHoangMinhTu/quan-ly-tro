<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Service;
use App\Models\UtilityMeter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractOpeningMeterLegacyCompatV4Test extends TestCase
{
    use RefreshDatabase;

    public function test_existing_room_meter_is_accepted_in_draft_before_service_selection(): void
    {
        $contract = Contract::factory()->create(['status' => 'DRAFT', 'start_date' => null]);
        $room = $contract->room;
        $owner = $room->boardingHouse->landlord->user;
        $service = Service::create([
            'boarding_house_id' => $room->boarding_house_id,
            'name' => 'Dien', 'type' => 'ELECTRICITY',
            'billing_method' => 'PER_UNIT', 'base_price' => 3800,
            'unit' => 'kWh', 'is_active' => true,
        ]);
        $room->services()->attach($service, ['is_active' => true]);
        $meter = UtilityMeter::create([
            'room_id' => $room->id, 'service_id' => $service->id,
            'initial_reading' => 0, 'is_active' => true,
        ]);

        $this->withToken($owner->createToken('test')->plainTextToken)
            ->putJson("/api/contracts/{$contract->id}", [
                'utility_openings' => [[
                    'utility_meter_id' => $meter->id,
                    'opening_reading' => 100,
                    'reading_date' => '2026-10-01',
                ]],
            ])->assertOk();

        $this->assertDatabaseHas('contract_utility_openings', [
            'contract_id' => $contract->id,
            'utility_meter_id' => $meter->id,
            'service_id' => $service->id,
            'opening_reading' => 100,
        ]);
        $this->assertSame(1, UtilityMeter::where('room_id', $room->id)->where('service_id', $service->id)->count());
    }
}
