<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingV2BoundaryCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $contract = Contract::factory()->create(['status' => 'ACTIVE', 'start_date' => '2026-10-01']);
        $room = $contract->room;
        $owner = $room->boardingHouse->landlord->user;
        $this->actingAs($owner, 'sanctum');
        config(['billing.v2_enabled' => true, 'billing.v2_pilot_user_ids' => [$owner->id]]);
        $service = $room->boardingHouse->services()->create([
            'name' => 'Điện test', 'type' => 'ELECTRICITY', 'billing_method' => 'PER_UNIT',
            'base_price' => 3000, 'unit' => 'kWh', 'is_active' => true,
        ]);
        $meter = $room->utilityMeters()->create(['service_id' => $service->id, 'initial_reading' => 0, 'is_active' => true]);
        $pair = $this->postJson("/api/billing-v2/meters/{$meter->id}/period-boundaries", [
            'start_observed_at' => '2026-11-02 08:00:00', 'end_observed_at' => '2026-11-30 08:00:00',
            'start_reading_value' => 100, 'end_reading_value' => 160,
        ])->assertOk()->json('data');

        return [$room, $contract, $meter, $pair];
    }

    private function payload(array $pair): array
    {
        return [
            'start_checkpoint_id' => $pair['start_checkpoint_id'], 'end_checkpoint_id' => $pair['end_checkpoint_id'],
            'start_observed_at' => '2026-11-03 09:00:00', 'end_observed_at' => '2026-11-29 18:00:00',
            'start_reading_value' => '105', 'end_reading_value' => '165',
            'reason' => 'Nhập nhầm ngày và chỉ số đo công tơ',
            'request_key' => '4e62cf40-95c3-4260-849f-1af968725310',
        ];
    }

    public function test_correction_keeps_original_readings_and_replays_without_duplicate(): void
    {
        [, , $meter, $pair] = $this->fixture();
        $url = "/api/billing-v2/meters/{$meter->id}/period-boundaries/correct";
        $payload = $this->payload($pair);
        $before = DB::table('utility_checkpoints')->count();
        $new = $this->postJson($url, $payload)->assertOk()->json('data');
        $this->assertDatabaseHas('utility_checkpoints', ['id' => $pair['start_checkpoint_id'], 'observed_at' => '2026-11-02 08:00:00', 'reading_value' => 100]);
        $this->assertDatabaseHas('utility_checkpoints', ['id' => $pair['end_checkpoint_id'], 'observed_at' => '2026-11-30 08:00:00', 'reading_value' => 160]);
        $this->assertDatabaseHas('utility_checkpoints', ['id' => $new['start_checkpoint_id'], 'revision' => 2, 'observed_at' => '2026-11-03 09:00:00', 'reading_value' => 105]);
        $this->assertDatabaseHas('utility_checkpoints', ['id' => $new['end_checkpoint_id'], 'revision' => 2, 'observed_at' => '2026-11-29 18:00:00', 'reading_value' => 165]);
        $this->assertSame($before + 2, DB::table('utility_checkpoints')->count());
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame($before + 2, DB::table('utility_checkpoints')->count());
        $this->assertDatabaseCount('utility_checkpoint_corrections', 1);
        $this->postJson($url, [...$payload, 'request_key' => 'b7be743b-186e-40b1-8867-cfd314615641'])->assertUnprocessable()->assertJsonValidationErrors('checkpoints');
        $this->postJson($url, [...$payload, 'start_reading_value' => '106'])->assertUnprocessable()->assertJsonValidationErrors('request_key');
    }

    public function test_bad_readings_and_other_landlord_cannot_edit(): void
    {
        [, , $meter, $pair] = $this->fixture();
        $url = "/api/billing-v2/meters/{$meter->id}/period-boundaries/correct";
        $payload = $this->payload($pair);
        $this->postJson("/api/billing-v2/meters/{$meter->id}/checkpoints", [
            'kind' => 'PERIOD_BOUNDARY', 'observed_at' => '2026-11-10 12:00:00', 'reading_value' => 120,
        ])->assertOk();
        $count = DB::table('utility_checkpoints')->count();
        $this->postJson($url, [...$payload, 'start_reading_value' => '130'])->assertUnprocessable()->assertJsonValidationErrors('reading_value');
        $this->assertSame($count, DB::table('utility_checkpoints')->count());
        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum');
        config(['billing.v2_pilot_user_ids' => [$other->id]]);
        $this->postJson($url, $payload)->assertNotFound();
        $this->assertDatabaseCount('utility_checkpoint_corrections', 0);
    }

    public function test_related_issued_invoice_rejects_checkpoint_correction(): void
    {
        [$room, $contract, $meter, $pair] = $this->fixture();
        $contract->invoices()->create([
            'room_id' => $room->id, 'billing_period' => '2026-11-01', 'status' => 'UNPAID',
            'issued_at' => '2026-11-30', 'subtotal' => 50000, 'total_amount' => 50000,
        ]);
        DB::table('utility_billing_periods')->insert([
            'utility_meter_id' => $meter->id, 'billing_period' => '2026-11-01',
            'timezone' => 'Asia/Ho_Chi_Minh', 'quantity' => 60, 'tariff_total' => 180000,
            'snapshot' => '{}', 'usage_start_at' => '2026-11-02 08:00:00',
            'usage_end_at' => '2026-11-30 08:00:00',
            'start_checkpoint_id' => $pair['start_checkpoint_id'], 'end_checkpoint_id' => $pair['end_checkpoint_id'],
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $count = DB::table('utility_checkpoints')->count();
        $this->postJson("/api/billing-v2/meters/{$meter->id}/period-boundaries/correct", $this->payload($pair))
            ->assertUnprocessable()->assertJsonValidationErrors('checkpoints');
        $this->assertSame($count, DB::table('utility_checkpoints')->count());
        $this->assertDatabaseCount('utility_checkpoint_corrections', 0);
    }
}
