<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Services\ContractOpeningCheckIn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ContractOpeningCheckInTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $contract = Contract::factory()->create(['status' => 'ACTIVE', 'start_date' => '2026-11-04', 'is_demo' => false]);
        $room = $contract->room;
        $service = $room->boardingHouse->services()->create(['name' => 'Điện', 'type' => 'ELECTRICITY', 'billing_method' => 'PER_UNIT', 'base_price' => 3800, 'unit' => 'kWh', 'is_active' => true]);
        $room->services()->attach($service, ['is_active' => true]);
        $contract->agreedServices()->create(['service_id' => $service->id, 'service_name' => 'Điện', 'service_type' => 'ELECTRICITY', 'billing_method' => 'PER_UNIT', 'unit' => 'kWh', 'base_price' => 3800, 'is_active' => true, 'effective_from' => '2026-11-04']);
        $meter = $room->utilityMeters()->create(['service_id' => $service->id, 'initial_reading' => 0, 'is_active' => true]);
        $contract->utilityOpenings()->create(['utility_meter_id' => $meter->id, 'service_id' => $service->id, 'service_name' => 'Điện', 'unit' => 'kWh', 'opening_reading' => 100, 'reading_date' => '2026-11-04']);
        return [$contract, $meter, $room->boardingHouse->landlord->user];
    }

    public function test_creates_one_check_in_from_contract_opening_and_is_idempotent(): void
    {
        [$contract, $meter, $actor] = $this->fixture();
        $sync = app(ContractOpeningCheckIn::class);
        $sync->sync($contract, $actor->id);
        $sync->sync($contract, $actor->id);
        $this->assertDatabaseHas('utility_checkpoints', ['utility_meter_id' => $meter->id, 'contract_id' => $contract->id, 'kind' => 'CHECK_IN', 'scope_key' => 'CHECK_IN:'.$contract->id, 'reading_value' => 100, 'observed_at' => '2026-11-04 00:00:00']);
        $this->assertSame(1, DB::table('utility_checkpoints')->count());
    }

    public function test_does_not_override_conflicting_check_in(): void
    {
        [$contract, $meter, $actor] = $this->fixture();
        DB::table('utility_checkpoints')->insert(['utility_meter_id' => $meter->id, 'contract_id' => $contract->id, 'kind' => 'CHECK_IN', 'scope_key' => 'CHECK_IN:'.$contract->id, 'revision' => 1, 'observed_at' => '2026-11-04 10:00:00', 'reading_value' => 99, 'confirmed_by' => $actor->id, 'created_at' => now()]);
        $this->expectException(ValidationException::class);
        try { app(ContractOpeningCheckIn::class)->sync($contract, $actor->id); }
        finally { $this->assertSame(1, DB::table('utility_checkpoints')->count()); }
    }
}
