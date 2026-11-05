<?php

namespace Tests\Feature;
use App\Models\Contract;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class BillingV2ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_abandonment_excludes_a_all_services_and_b_pays_confirmed_rent_and_meter_share(): void
    {
        [$a, $room, $meter] = $this->fixture('TIERED');
        $this->leave($a, $meter->id, '2026-10-15 10:00:00', 130, 'TENANT_ABANDONMENT');
        $b = $this->next($a, $meter->id, '2026-10-20 10:00:00', 135);
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-01 00:00:00', 150);
        $url = '/api/billing-v2/boarding-houses/'.$room->boarding_house_id.'/monthly-invoices';
        $this->postJson($url, ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonPath('data.0.status', 'BLOCKED');
        $this->assertDatabaseCount('invoices', 0);
        $data = ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00', 'confirmations' => [['contract_id' => $b->id, 'rent_amount' => '1500000', 'reason' => 'Thỏa thuận kỳ đầu của B']]];
        $this->postJson($url, $data)->assertOk()->assertJsonPath('data.0.status', 'CREATED');
        $this->postJson($url, $data)->assertOk()->assertJsonPath('data.0.status', 'EXISTING');
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseHas('invoices', ['contract_id' => $b->id, 'total_amount' => 1548000, 'status' => 'DRAFT']);
        $this->assertDatabaseMissing('invoices', ['contract_id' => $a->id]);
        $this->assertSame(50, (int) DB::table('utility_charge_allocations')->sum('quantity'));
        $this->assertSame(35, (int) DB::table('utility_charge_allocations')->where('responsible_party', 'LANDLORD')->sum('quantity'));
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-01 00:00:00', 155);
        $prefix = '/api/billing-v2/rooms/'.$room->id.'/monthly-recalculations';
        $preview = $this->postJson($prefix.'/preview', ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk();
        $this->assertSame('PREVIEWED', $preview->json('data.status'));
        $this->postJson($prefix.'/'.$preview->json('data.token').'/confirm', ['confirmed' => true, 'reason' => 'Sửa mốc chốt cuối kỳ'])->assertOk();
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseMissing('invoices', ['contract_id' => $a->id]);
        $this->assertDatabaseHas('invoices', ['contract_id' => $b->id, 'total_amount' => 1565454]);
        $this->assertSame(55, (int) DB::table('utility_charge_allocations')->sum('quantity'));
        $this->assertSame(180000, (int) DB::table('utility_charge_allocations')->sum('tariff_amount'));
    }

    public function test_recalculation_updates_both_sequential_drafts_once_and_each_can_be_explicitly_issued(): void
    {
        [$a, $room, $meter] = $this->fixture('TIERED');
        $this->leave($a, $meter->id, '2026-10-15 10:00:00', 130);
        $b = $this->next($a, $meter->id, '2026-10-15 10:00:00', 130);
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-01 00:00:00', 150);
        $data = ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00', 'confirmations' => [
            ['contract_id' => $a->id, 'rent_amount' => '1000000', 'reason' => 'Thỏa thuận trả phòng A'],
            ['contract_id' => $b->id, 'rent_amount' => '1500000', 'reason' => 'Thỏa thuận nhận phòng B']]];
        $this->postJson('/api/billing-v2/boarding-houses/'.$room->boarding_house_id.'/monthly-invoices', $data)->assertOk()->assertJsonPath('data.0.status', 'CREATED');
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-01 00:00:00', 160);
        $prefix = '/api/billing-v2/rooms/'.$room->id.'/monthly-recalculations';
        $preview = $this->postJson($prefix.'/preview', ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonCount(2, 'data.after.invoices');
        $this->postJson($prefix.'/'.$preview->json('data.token').'/confirm', ['confirmed' => true, 'reason' => 'Đối soát toàn công tơ/kỳ'])->assertOk();
        $this->assertDatabaseCount('invoices', 2);
        $this->assertDatabaseCount('invoice_items', 4);
        $this->assertDatabaseHas('invoices', ['contract_id' => $a->id, 'total_amount' => 1100000]);
        $this->assertDatabaseHas('invoices', ['contract_id' => $b->id, 'total_amount' => 1600000]);
        foreach ($room->invoices()->get() as $invoice) {
            $this->postJson('/api/billing-v2/invoices/'.$invoice->id.'/issue')->assertOk();
        }
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_monthly_missing_period_close_rolls_back_all_components(): void
    {
        [$c, $room] = $this->fixture('TIERED');
        $this->postJson('/api/billing-v2/boarding-houses/'.$room->boarding_house_id.'/monthly-invoices', ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonPath('data.0.status', 'PENDING_FINAL_SETTLEMENT');
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseCount('monthly_invoice_compositions', 0);
    }

    public function test_shared_dormitory_meter_without_residency_and_checkpoint_evidence_is_blocked_without_zero_invoice(): void
    {
        [, $privateRoom] = $this->fixture();
        $house = $privateRoom->boardingHouse;
        $dormitory = $house->rooms()->create(['room_code' => 'KTX-SHARED', 'room_type' => 'DORMITORY', 'monthly_rent' => null, 'status' => 'AVAILABLE']);
        $dormitory->beds()->createMany([['bed_code' => 'G01', 'status' => 'OCCUPIED'], ['bed_code' => 'G02', 'status' => 'OCCUPIED']]);
        $service = $house->services()->create(['name' => 'KTX shared electricity', 'type' => 'ELECTRICITY', 'billing_method' => 'TIERED', 'base_price' => 0, 'unit' => 'kWh', 'is_active' => true]);
        $service->priceTiers()->createMany([['from_quantity' => 0, 'to_quantity' => 20, 'unit_price' => 2000, 'tier_order' => 1], ['from_quantity' => 20, 'to_quantity' => null, 'unit_price' => 4000, 'tier_order' => 2]]);
        $dormitory->utilityMeters()->create(['service_id' => $service->id, 'meter_code' => 'KTX-SHARED-METER', 'initial_reading' => 100, 'is_active' => true]);

        $response = $this->postJson('/api/billing-v2/boarding-houses/'.$house->id.'/monthly-invoices', [
            'billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00',
        ])->assertOk();

        $dormitoryResult = collect($response->json('data'))->firstWhere('room_id', $dormitory->id);
       $this->assertSame('NO_ELIGIBLE_CONTRACT', $dormitoryResult['status']);
$this->assertSame([], $dormitoryResult['invoices']);
        $this->assertDatabaseMissing('invoices', ['room_id' => $dormitory->id]);
        $this->assertDatabaseMissing('utility_billing_periods', ['utility_meter_id' => $dormitory->utilityMeters()->value('id')]);
        $this->assertDatabaseCount('monthly_invoice_compositions', 0);
    }

    public function test_saving_two_period_boundary_readings_is_atomic_idempotent_and_does_not_create_an_invoice(): void
    {
        [, , $meter] = $this->fixture();
        $url = "/api/billing-v2/meters/{$meter->id}/period-boundaries";
        $data = [
            'start_observed_at' => '2026-11-02 08:00:00', 'start_reading_value' => '150',
            'end_observed_at' => '2026-11-30 08:00:00', 'end_reading_value' => '180',
        ];
        // Existing intermediate evidence makes only the SECOND new reading invalid.
        // The first insert must roll back with it (no half-saved form).
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-20 08:00:00', 170);
        $this->postJson($url, [...$data, 'end_reading_value' => '160'])->assertUnprocessable()->assertJsonValidationErrors('reading_value');
        $this->assertDatabaseMissing('utility_checkpoints', ['utility_meter_id' => $meter->id, 'scope_key' => 'P:2026-11-02 08:00:00']);

        $response = $this->postJson($url, $data)->assertOk();
        $this->assertNotNull($response->json('data.start_checkpoint_id'));
        $this->assertNotNull($response->json('data.end_checkpoint_id'));
        $this->assertDatabaseHas('utility_checkpoints', ['utility_meter_id' => $meter->id, 'observed_at' => $data['start_observed_at'], 'reading_value' => 150]);
        $this->assertDatabaseHas('utility_checkpoints', ['utility_meter_id' => $meter->id, 'observed_at' => $data['end_observed_at'], 'reading_value' => 180]);
        $count = DB::table('utility_checkpoints')->where('utility_meter_id', $meter->id)->count();
        $this->postJson($url, $data)->assertOk()->assertJsonPath('data.start_checkpoint_id', $response->json('data.start_checkpoint_id'));
        $this->assertSame($count, DB::table('utility_checkpoints')->where('utility_meter_id', $meter->id)->count());
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_private_room_calculates_separate_electricity_and_water_windows_under_one_invoice_month(): void
    {
        [$contract, $room, $electric] = $this->fixture('PER_UNIT');
        $waterService = $room->boardingHouse->services()->create([
            'name' => 'Water', 'type' => 'WATER', 'billing_method' => 'PER_UNIT',
            'base_price' => 15000, 'unit' => 'm3', 'is_active' => true,
        ]);
        $room->services()->attach($waterService, ['is_active' => true]);
        $contract->agreedServices()->create([
            'service_id' => $waterService->id, 'service_name' => $waterService->name,
            'service_type' => 'WATER', 'billing_method' => 'PER_UNIT',
            'base_price' => 15000, 'unit' => 'm3', 'effective_from' => '2026-09-01',
            'is_active' => true, 'pricing_snapshot' => [],
        ]);
        $water = $room->utilityMeters()->create([
            'service_id' => $waterService->id, 'initial_reading' => 0, 'is_active' => true,
        ]);
        $this->point($water->id, 'CHECK_IN', '2026-10-01 00:00:00', 0, $contract->id);
        $this->point($electric->id, 'PERIOD_BOUNDARY', '2026-11-03 00:00:00', 100);
        $this->point($electric->id, 'PERIOD_BOUNDARY', '2026-12-01 00:00:00', 110);
        $this->point($water->id, 'PERIOD_BOUNDARY', '2026-11-04 00:00:00', 0);
        $this->point($water->id, 'PERIOD_BOUNDARY', '2026-12-01 00:00:00', 5);
        $payload = [
            'billing_period' => '2026-11-01',
            'usage_start_at' => '2026-11-03 00:00:00',
            'usage_end_at' => '2026-12-01 00:00:00',
            'meter_periods' => [
                ['utility_meter_id' => $electric->id, 'usage_start_at' => '2026-11-03 00:00:00', 'usage_end_at' => '2026-12-01 00:00:00'],
                ['utility_meter_id' => $water->id, 'usage_start_at' => '2026-11-04 00:00:00', 'usage_end_at' => '2026-12-01 00:00:00'],
            ],
        ];
        $url = "/api/billing-v2/rooms/{$room->id}/generate";
        $this->postJson($url, $payload)->assertOk();
        $this->assertDatabaseHas('utility_billing_periods', [
            'utility_meter_id' => $electric->id, 'billing_period' => '2026-11-01',
            'usage_start_at' => '2026-11-03 00:00:00', 'quantity' => 10,
        ]);
        $this->assertDatabaseHas('utility_billing_periods', [
            'utility_meter_id' => $water->id, 'billing_period' => '2026-11-01',
            'usage_start_at' => '2026-11-04 00:00:00', 'quantity' => 5,
        ]);
        $this->assertDatabaseCount('utility_billing_periods', 2);
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.replayed', true);
        $invoice = $room->invoices()->whereDate('billing_period', '2026-11-01')->firstOrFail();
        $this->postJson('/api/billing-v2/invoices/'.$invoice->id.'/issue')->assertOk();
        $this->assertDatabaseCount('utility_billing_periods', 2);
    }

    public function test_mixed_meter_windows_reject_missing_or_foreign_meters_without_writing(): void
    {
        [, $room, $meter] = $this->fixture();
        $data = [
            'billing_period' => '2026-11-01',
            'usage_start_at' => '2026-11-01 00:00:00',
            'usage_end_at' => '2026-12-01 00:00:00',
            'meter_periods' => [[
                'utility_meter_id' => $meter->id + 999999,
                'usage_start_at' => '2026-11-03 00:00:00',
                'usage_end_at' => '2026-12-01 00:00:00',
            ]],
        ];
        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", $data)
            ->assertUnprocessable()->assertJsonValidationErrors('meter_periods');
        $this->assertDatabaseCount('utility_billing_periods', 0);
    }

    private function fixture(string $method = 'PER_UNIT', string $type = 'ELECTRICITY'): array
    {
        $this->travelTo(now()->setDate(2026, 11, 2)->startOfDay());
        $contract = Contract::factory()->create(['status' => 'ACTIVE', 'start_date' => '2026-10-01']);
        $room = $contract->room;
        $owner = $room->boardingHouse->landlord->user;
        $this->actingAs($owner, 'sanctum');
        config(['billing.v2_pilot_user_ids' => [$owner->id]]);
        $service = $room->boardingHouse->services()->create(['name' => 'Utility test', 'type' => $type, 'billing_method' => $method, 'base_price' => 3000, 'unit' => $type === 'WATER' ? 'm3' : 'kWh', 'is_active' => true]);
        if ($method === 'TIERED') {
            $service->priceTiers()->createMany([['from_quantity' => 0, 'to_quantity' => 20, 'unit_price' => 2000, 'tier_order' => 1], ['from_quantity' => 20, 'to_quantity' => null, 'unit_price' => 4000, 'tier_order' => 2]]);
        }
        $room->services()->attach($service, ['is_active' => true]);
        $contract->service_terms_version = 1;
        $contract->save();
        $contract->agreedServices()->create(['service_id' => $service->id, 'service_name' => $service->name, 'service_type' => $service->type, 'billing_method' => $method, 'base_price' => 3000, 'unit' => $service->unit, 'effective_from' => '2026-09-01', 'is_active' => true, 'pricing_snapshot' => $service->priceTiers->map->only(['from_quantity', 'to_quantity', 'unit_price', 'tier_order'])->all()]);
        $meter = $room->utilityMeters()->create(['service_id' => $service->id, 'initial_reading' => 0, 'is_active' => true]);
        $contract->handoverReports()->create(['type' => 'CHECK_IN', 'status' => 'CONFIRMED', 'items' => [], 'confirmed_at' => now()]);
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-10-01 00:00:00', 100);
        $this->point($meter->id, 'CHECK_IN', '2026-10-01 00:00:00', 100, $contract->id);

        return [$contract, $room, $meter, $service];
    }

    private function point(int $meter, string $kind, string $at, int $value, ?int $contract = null): void
    {
        $this->postJson("/api/billing-v2/meters/{$meter}/checkpoints", ['kind' => $kind, 'observed_at' => $at, 'reading_value' => $value, 'contract_id' => $contract])->assertOk();
    }

    private function leave(Contract $contract, int $meter, string $at, int $value, string $reason = 'MUTUAL_EARLY_END'): void
    {
        $this->postJson("/api/billing-v2/contracts/{$contract->id}/checkout", ['actual_ended_at' => $at, 'reason' => $reason, 'confirm_no_held_deposit' => $reason === 'TENANT_ABANDONMENT', 'readings' => [['utility_meter_id' => $meter, 'reading_value' => $value]]])->assertOk();
    }

    private function next(Contract $old, int $meter, string $at, int $value): Contract
    {
        $new = Contract::factory()->create(['room_id' => $old->room_id, 'status' => 'ACTIVE', 'start_date' => substr($at, 0, 10)]);
        $new->service_terms_version = 1;
        $new->save();
        foreach ($old->agreedServices()->get() as $term) {
            $new->agreedServices()->create([...$term->only(['service_id', 'service_name', 'service_type', 'billing_method', 'base_price', 'unit', 'vat_rate', 'pricing_snapshot', 'is_active']), 'effective_from' => substr($at, 0, 10)]);
        }
        $new->handoverReports()->create(['type' => 'CHECK_IN', 'status' => 'CONFIRMED', 'items' => [], 'confirmed_at' => now()]);
        $this->point($meter, 'CHECK_IN', $at, $value, $new->id);

        return $new;
    }

    #[TestWith(['PER_UNIT', 'ELECTRICITY', '150000'])]
    #[TestWith(['TIERED', 'ELECTRICITY', '160000'])]
    #[TestWith(['PER_UNIT', 'WATER', '150000'])]
    #[TestWith(['TIERED', 'WATER', '160000'])]
    public function test_case_a_uses_confirmed_period_close_not_intermediate_readings(string $method, string $type, string $amount): void
    {
        [$contract, $room, $meter] = $this->fixture($method, $type);
        $meter->readings()->create(['reading_date' => '2026-10-15', 'reading_value' => 125]);
        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertUnprocessable()->assertJsonValidationErrors('checkpoints');
        $this->assertDatabaseCount('invoices', 0);
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-01 00:00:00', 150);

        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonPath('data.meters.0.quantity', '50.00')->assertJsonPath('data.meters.0.total', $amount);

        $this->assertDatabaseHas('utility_charge_allocations', ['contract_id' => $contract->id, 'quantity' => 50, 'tariff_amount' => $amount, 'responsible_party' => 'TENANT']);
        $this->assertDatabaseCount('invoice_items', 1);
        $this->assertDatabaseHas('invoice_items', ['quantity' => 50, 'amount' => $amount, 'source' => 'AUTO']);
    }

    #[TestWith(['2026-11-01 00:00:00', '2026-12-01 00:00:00'])]
    #[TestWith(['2026-11-02 07:15:00', '2026-12-02 19:45:00'])]
    #[TestWith(['2026-11-02 07:15:00', '2026-11-30 19:45:00'])]
    #[TestWith(['2026-11-05 06:30:00', '2026-11-28 22:10:00'])]
    public function test_owner_selected_usage_window_is_independent_of_invoice_month_and_saved_with_checkpoint_evidence(string $start, string $end): void
    {
        [, $room, $meter] = $this->fixture();
        $this->point($meter->id, 'PERIOD_BOUNDARY', $start, 150);
        $this->point($meter->id, 'PERIOD_BOUNDARY', $end, 175);

        $response = $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", [
            'billing_period' => '2027-01-01', 'usage_start_at' => $start, 'usage_end_at' => $end,
        ])->assertOk()->assertJsonPath('data.meters.0.quantity', '25.00');

        $cycle = DB::table('utility_billing_periods')->first();
        $this->assertSame('2027-01-01', $cycle->billing_period);
        $this->assertSame($start, $cycle->usage_start_at);
        $this->assertSame($end, $cycle->usage_end_at);
        $this->assertNotNull($cycle->start_checkpoint_id);
        $this->assertNotNull($cycle->end_checkpoint_id);
        $this->assertNotNull($cycle->start_confirmed_by);
        $this->assertNotNull($cycle->end_confirmed_by);
        $this->assertSame($start, $response->json('data.meters.0.usage_start_at'));
    }

    public function test_adjacent_custom_cycles_share_boundary_without_double_count_and_overlaps_are_blocked(): void
    {
        [, $room, $meter] = $this->fixture();
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-02 08:30:00', 100);
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-30 21:15:00', 130);
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-12-02 09:00:00', 150);
        $url = "/api/billing-v2/rooms/{$room->id}/generate";

        $this->postJson($url, ['billing_period' => '2026-12-01', 'usage_start_at' => '2026-11-02 08:30:00', 'usage_end_at' => '2026-11-30 21:15:00'])
            ->assertOk()->assertJsonPath('data.meters.0.quantity', '30.00');
        $this->postJson($url, ['billing_period' => '2027-01-01', 'usage_start_at' => '2026-11-30 21:15:00', 'usage_end_at' => '2026-12-02 09:00:00'])
            ->assertOk()->assertJsonPath('data.meters.0.quantity', '20.00');

        $this->postJson($url, ['billing_period' => '2027-02-01', 'usage_start_at' => '2026-11-20 00:00:00', 'usage_end_at' => '2026-12-05 00:00:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('usage_window');

        $this->assertDatabaseCount('utility_billing_periods', 2);
        $this->assertSame(50, (int) DB::table('utility_billing_periods')->sum('quantity'));
        $this->assertSame(50, (int) DB::table('utility_charge_allocations')->sum('quantity'));
    }

    public function test_custom_tiered_window_keeps_checkout_and_checkin_segments_on_their_own_invoices_when_invoice_month_is_december(): void
    {
        [$a, $room, $meter] = $this->fixture('TIERED');
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-02 08:30:00', 100);
        $this->leave($a, $meter->id, '2026-11-20 10:00:00', 130);
        $b = $this->next($a, $meter->id, '2026-11-20 10:00:00', 130);
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-12-02 09:00:00', 150);

        $response = $this->postJson("/api/billing-v2/boarding-houses/{$room->boarding_house_id}/monthly-invoices", [
            'billing_period' => '2026-12-01', 'usage_start_at' => '2026-11-02 08:30:00', 'usage_end_at' => '2026-12-02 09:00:00',
        ])->assertOk()->assertJsonPath('data.0.status', 'CREATED');

        $this->assertCount(2, $response->json('data.0.invoices'));
        $this->assertDatabaseHas('utility_charge_allocations', ['contract_id' => $a->id, 'quantity' => 30, 'tariff_amount' => 96000, 'responsible_party' => 'TENANT']);
        $this->assertDatabaseHas('utility_charge_allocations', ['contract_id' => $b->id, 'quantity' => 20, 'tariff_amount' => 64000, 'responsible_party' => 'TENANT']);
        $this->assertDatabaseHas('invoices', ['contract_id' => $a->id, 'billing_period' => '2026-12-01', 'total_amount' => 96000]);
        $this->assertDatabaseHas('invoices', ['contract_id' => $b->id, 'billing_period' => '2026-12-01']);
        $this->assertSame(160000, (int) DB::table('utility_billing_periods')->sum('tariff_total'));
    }

    public function test_custom_tiered_window_keeps_abandonment_and_vacancy_with_landlord_when_b_is_billed_next_month(): void
    {
        [$a, $room, $meter] = $this->fixture('TIERED');
        $this->postJson("/api/billing-v2/contracts/{$a->id}/deposits", ['event' => 'RECEIVED', 'amount' => 3000000, 'request_key' => 'custom-cycle-deposit', 'occurred_at' => '2026-10-01 00:00:00'])->assertOk();
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-02 08:30:00', 100);
        $this->leave($a, $meter->id, '2026-11-15 10:00:00', 130, 'TENANT_ABANDONMENT');
        $b = $this->next($a, $meter->id, '2026-11-20 10:00:00', 135);
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-12-02 09:00:00', 150);

        $response = $this->postJson("/api/billing-v2/boarding-houses/{$room->boarding_house_id}/monthly-invoices", [
            'billing_period' => '2026-12-01', 'usage_start_at' => '2026-11-02 08:30:00', 'usage_end_at' => '2026-12-02 09:00:00',
        ])->assertOk()->assertJsonPath('data.0.status', 'CREATED');

        $this->assertCount(1, $response->json('data.0.invoices'));
        $this->assertDatabaseHas('utility_charge_allocations', ['contract_id' => $a->id, 'quantity' => 30, 'responsible_party' => 'LANDLORD', 'reason' => 'ABANDONMENT']);
        $this->assertDatabaseHas('utility_charge_allocations', ['contract_id' => null, 'quantity' => 5, 'responsible_party' => 'LANDLORD', 'reason' => 'VACANT']);
        $this->assertDatabaseHas('utility_charge_allocations', ['contract_id' => $b->id, 'quantity' => 15, 'responsible_party' => 'TENANT']);
        $this->assertDatabaseMissing('invoices', ['contract_id' => $a->id, 'billing_period' => '2026-12-01']);
        $this->assertDatabaseHas('invoices', ['contract_id' => $b->id, 'billing_period' => '2026-12-01']);
        $settlementId = DB::table('contract_settlements')->where('contract_id', $a->id)->value('id');
        $this->assertDatabaseHas('contract_settlement_usages', ['contract_settlement_id' => $settlementId, 'checkout_period_quantity' => 30, 'responsible_party' => 'LANDLORD', 'status' => 'FINAL_RECONCILED']);
        $this->assertSame(50, (int) DB::table('utility_charge_allocations')->sum('quantity'));
    }

    #[TestWith([false, 'MUTUAL_EARLY_END', 30, 20, 0])]
    #[TestWith([true, 'MUTUAL_EARLY_END', 30, 15, 5])]
    #[TestWith([true, 'TENANT_ABANDONMENT', 0, 15, 35])]
    public function test_cases_b_c_f_g_partition_one_tiered_total_without_resetting_tiers(bool $vacancy, string $reason, int $aQuantity, int $bQuantity, int $ownerQuantity): void
    {
        [$a, $room, $meter] = $this->fixture('TIERED');
        $this->leave($a, $meter->id, '2026-10-15 10:00:00', 130, $reason);
        $b = $this->next($a, $meter->id, $vacancy ? '2026-10-20 10:00:00' : '2026-10-15 10:00:00', $vacancy ? 135 : 130);
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-01 00:00:00', 150);

        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonPath('data.meters.0.total', '160000');

        $shares = DB::table('utility_charge_allocations')->get();
        $this->assertSame($aQuantity, (int) $shares->where('contract_id', $a->id)->where('responsible_party', 'TENANT')->sum('quantity'));
        $this->assertSame($bQuantity, (int) $shares->where('contract_id', $b->id)->sum('quantity'));
        $this->assertSame($ownerQuantity, (int) $shares->where('responsible_party', 'LANDLORD')->sum('quantity'));
        $this->assertSame(50, (int) $shares->sum('quantity'));
        $this->assertSame(160000, (int) $shares->sum('tariff_amount'));
        $this->assertCount(0, $shares->where('responsible_party', 'LANDLORD')->whereNotNull('invoice_item_id'));
        $this->assertCount(0, $shares->whereNotNull('supplier_cost'));
        $this->assertDatabaseCount('invoices', $aQuantity === 0 ? 1 : 2);
    }

    public function test_case_d_new_tenant_pays_only_from_confirmed_move_in(): void
    {
        [$a, $room, $meter] = $this->fixture();
        $this->leave($a, $meter->id, '2026-10-01 00:00:00', 100);
        $b = $this->next($a, $meter->id, '2026-10-20 10:00:00', 250);
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-01 00:00:00', 280);

        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk();

        $this->assertDatabaseHas('utility_charge_allocations', ['contract_id' => $b->id, 'quantity' => 30, 'tariff_amount' => 90000, 'responsible_party' => 'TENANT']);
        $this->assertDatabaseHas('utility_charge_allocations', ['contract_id' => null, 'quantity' => 150, 'responsible_party' => 'LANDLORD']);
    }

    public function test_case_h_rebuilds_draft_auto_items_with_new_checkpoint_revision_without_duplicates(): void
    {
        [$a, $room, $meter] = $this->fixture();
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-01 00:00:00', 150);
        $url = "/api/billing-v2/boarding-houses/{$room->boarding_house_id}/monthly-invoices";
        $this->postJson($url, ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonPath('data.0.status', 'CREATED');
        $invoice = $a->invoices()->firstOrFail();
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-01 00:00:00', 160);

        $this->postJson("/api/invoices/{$invoice->id}/generate")->assertUnprocessable()->assertJsonValidationErrors('invoice_id');
        $prefix = "/api/billing-v2/rooms/{$room->id}/monthly-recalculations";
        $preview = $this->postJson($prefix.'/preview', ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonPath('data.status', 'PREVIEWED');
        $this->travel(2)->seconds();
        $this->postJson($prefix.'/'.$preview->json('data.token').'/confirm', ['confirmed' => true, 'reason' => 'Cập nhật chỉ số cuối kỳ đã xác nhận'])->assertOk();

        $this->assertDatabaseCount('invoice_obligations', 1);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_items', 2);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'quantity' => 60, 'amount' => 180000]);
        $this->assertDatabaseCount('utility_checkpoints', 4);
    }

    #[TestWith(['UNPAID'])]
    #[TestWith(['PAID'])]
    public function test_does_not_mutate_issued_or_paid_invoice_or_snapshot(string $status): void
    {
        [$a, $room, $meter] = $this->fixture();
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-01 00:00:00', 150);
        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk();
        $invoice = $a->invoices()->firstOrFail();
        $invoice->update(['status' => $status]);
        $snapshot = DB::table('utility_billing_periods')->value('snapshot');

        $this->postJson("/api/invoices/{$invoice->id}/generate")->assertUnprocessable();

        $this->assertSame($snapshot, DB::table('utility_billing_periods')->value('snapshot'));
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'amount' => 150000]);
    }

    public function test_abandonment_forfeits_only_actual_held_deposit_and_preserves_previous_debt(): void
    {
        [$a, , $meter] = $this->fixture();
        $debt = $a->invoices()->create(['room_id' => $a->room_id, 'billing_period' => '2026-09-01', 'status' => 'UNPAID', 'subtotal' => 100000, 'total_amount' => 100000]);
        $payload = ['event' => 'RECEIVED', 'amount' => 1000000, 'request_key' => 'receipt-1', 'occurred_at' => '2026-10-01 00:00:00'];
        $this->postJson("/api/billing-v2/contracts/{$a->id}/deposits", $payload)->assertOk();
        $this->postJson("/api/billing-v2/contracts/{$a->id}/deposits", $payload)->assertOk();
        $this->postJson("/api/billing-v2/contracts/{$a->id}/deposits", [...$payload, 'event' => 'REFUNDED', 'amount' => 200000, 'request_key' => 'refund-1'])->assertOk();

        $this->leave($a, $meter->id, '2026-10-15 10:00:00', 130, 'TENANT_ABANDONMENT');
        $this->leave($a, $meter->id, '2026-10-15 10:00:00', 130, 'TENANT_ABANDONMENT');

        $this->assertDatabaseHas('contract_deposit_entries', ['contract_id' => $a->id, 'event' => 'FORFEITED', 'amount' => 800000]);
        $this->assertDatabaseCount('contract_deposit_entries', 3);
        $this->assertSame('100000.00', $debt->fresh()->total_amount);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_case_i_rejects_cross_landlord_access_with_404(): void
    {
        [$a, $room, $meter] = $this->fixture();
        $otherUser = User::factory()->create();
        $this->actingAs($otherUser, 'sanctum');
        config(['billing.v2_pilot_user_ids' => [$otherUser->id]]);

        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertNotFound();
        $this->getJson("/api/billing-v2/rooms/{$room->id}?billing_period=2026-10-01")->assertNotFound();
        $this->postJson("/api/billing-v2/meters/{$meter->id}/checkpoints", [])->assertNotFound();
        $this->postJson("/api/billing-v2/contracts/{$a->id}/checkout", [])->assertNotFound();
        $this->postJson("/api/billing-v2/contracts/{$a->id}/deposits", [])->assertNotFound();

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('contract_terminations', 0);
    }

    public function test_tariff_conflict_blocks_all_writes_instead_of_choosing_a_contract_price(): void
    {
        [$a, $room, $meter, $service] = $this->fixture('TIERED');
        $this->leave($a, $meter->id, '2026-10-15 10:00:00', 130);
        $b = $this->next($a, $meter->id, '2026-10-15 10:00:00', 130);
        $b->service_terms_version = 1;
        $b->save();
        $b->agreedServices()->create(['service_id' => $service->id, 'service_name' => $service->name, 'service_type' => 'ELECTRICITY', 'billing_method' => 'PER_UNIT', 'base_price' => 9000, 'unit' => 'kWh', 'effective_from' => '2026-10-15', 'is_active' => true]);
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-01 00:00:00', 150);

        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertUnprocessable()->assertJsonValidationErrors('tariff_conflict');

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('utility_billing_periods', 0);
    }

    public function test_legacy_invoice_blocks_v2_and_keeps_history_unchanged(): void
    {
        [$a, $room, $meter] = $this->fixture();
        $invoice = $a->invoices()->create(['room_id' => $room->id, 'billing_period' => '2026-10-01', 'status' => 'DRAFT']);
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-01 00:00:00', 150);

        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertUnprocessable()->assertJsonValidationErrors('billing_period');

        $this->assertModelExists($invoice);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('utility_charge_allocations', 0);
    }

    public function test_case_e_contract_entirely_inside_month_uses_its_40_units_only(): void
    {
        [$a, $room, $meter] = $this->fixture();
        $this->leave($a, $meter->id, '2026-10-01 00:00:00', 100);
        $b = $this->next($a, $meter->id, '2026-10-10 09:00:00', 200);
        $this->leave($b, $meter->id, '2026-10-25 09:00:00', 240);
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-01 00:00:00', 250);

        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk();

        $this->assertDatabaseHas('utility_charge_allocations', ['contract_id' => $b->id, 'quantity' => 40, 'tariff_amount' => 120000]);
        $this->assertDatabaseHas('contract_terminations', ['contract_id' => $b->id, 'reason' => 'MUTUAL_EARLY_END', 'actual_ended_at' => '2026-10-25 09:00:00']);
        $this->assertDatabaseCount('contract_deposit_entries', 0);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_normal_expiry_does_not_forfeit_nominal_or_received_deposit(): void
    {
        [$a, , $meter] = $this->fixture();
        $a->update(['end_date' => '2026-10-31']);
        $this->postJson("/api/billing-v2/contracts/{$a->id}/deposits", ['event' => 'RECEIVED', 'amount' => 1000000, 'request_key' => 'cash-1', 'occurred_at' => '2026-10-01 00:00:00'])->assertOk();

        $this->leave($a, $meter->id, '2026-10-31 10:00:00', 150, 'NORMAL_EXPIRY');

        $this->assertSame('EXPIRED', $a->fresh()->status);
        $this->assertDatabaseCount('contract_deposit_entries', 1);
        $this->assertDatabaseMissing('contract_deposit_entries', ['event' => 'FORFEITED']);
    }

    public function test_missing_checkout_meter_rolls_back_termination_handover_and_ledger(): void
    {
        [$a] = $this->fixture();

        $this->postJson("/api/billing-v2/contracts/{$a->id}/checkout", ['actual_ended_at' => '2026-10-15 10:00:00', 'reason' => 'TENANT_ABANDONMENT', 'readings' => []])->assertUnprocessable()->assertJsonValidationErrors('readings');

        $this->assertSame('ACTIVE', $a->fresh()->status);
        $this->assertDatabaseCount('contract_terminations', 0);
        $this->assertDatabaseCount('contract_deposit_entries', 0);
        $this->assertDatabaseMissing('contract_handover_reports', ['type' => 'CHECK_OUT']);
    }

    public function test_flexible_period_marker_accepts_owner_selected_timestamp_but_rejects_conflicting_same_time_reading(): void
    {
        [$a, , $meter] = $this->fixture();

        $this->postJson("/api/billing-v2/meters/{$meter->id}/checkpoints", ['kind' => 'PERIOD_BOUNDARY', 'observed_at' => '2026-10-15 00:00:00', 'reading_value' => 125])->assertOk();
        $this->postJson("/api/billing-v2/meters/{$meter->id}/checkpoints", ['kind' => 'CHECK_IN', 'contract_id' => $a->id, 'observed_at' => '2026-10-01 00:00:00', 'reading_value' => 101])->assertUnprocessable()->assertJsonValidationErrors('reading_value');

        $this->assertDatabaseCount('utility_checkpoints', 3);
    }

    public function test_splitting_a_single_dong_preserves_integer_money_and_total(): void
    {
        [$a, $room, $meter, $service] = $this->fixture();
        $service->update(['base_price' => '0.01']);
        $a->agreedServices()->update(['base_price' => '0.01']);
        $this->leave($a, $meter->id, '2026-10-15 10:00:00', 130);
        $b = $this->next($a, $meter->id, '2026-10-15 10:00:00', 130);
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-01 00:00:00', 150);

        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonPath('data.meters.0.total', '1');

        $this->assertDatabaseHas('utility_charge_allocations', ['contract_id' => $a->id, 'tariff_amount' => 1]);
        $this->assertDatabaseHas('utility_charge_allocations', ['contract_id' => $b->id, 'tariff_amount' => 0]);
        $this->assertSame(1, (int) DB::table('invoice_items')->sum('amount'));
    }

    public function test_december_closes_on_january_first_of_next_year(): void
    {
        [$a, $room, $meter] = $this->fixture();
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-12-01 00:00:00', 200);
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2027-01-01 00:00:00', 250);

        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-12-01', 'usage_start_at' => '2026-12-01 00:00:00', 'usage_end_at' => '2027-01-01 00:00:00'])->assertOk()->assertJsonPath('data.meters.0.quantity', '50.00');

        $this->assertDatabaseHas('invoices', ['contract_id' => $a->id, 'billing_period' => '2026-12-01', 'total_amount' => 150000]);
    }

    public function test_deposit_key_conflict_and_over_refund_are_rejected_without_extra_entries(): void
    {
        [$a] = $this->fixture();
        $url = "/api/billing-v2/contracts/{$a->id}/deposits";
        $data = ['event' => 'RECEIVED', 'amount' => 1000, 'request_key' => 'cash-1', 'occurred_at' => '2026-10-01 00:00:00'];
        $this->postJson($url, $data)->assertOk();

        $this->postJson($url, [...$data, 'amount' => 2000])->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $this->postJson($url, [...$data, 'event' => 'REFUNDED', 'amount' => 1001, 'request_key' => 'refund-1'])->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->assertDatabaseCount('contract_deposit_entries', 1);
    }

    public function test_direct_edit_or_delete_of_allocated_auto_item_returns_validation_not_500(): void
    {
        [$a, $room, $meter] = $this->fixture();
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-01 00:00:00', 150);
        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk();
        $item = $a->invoices()->firstOrFail()->items()->firstOrFail();

        $this->putJson("/api/invoice-items/{$item->id}", ['type' => 'ELECTRICITY', 'description' => 'test', 'quantity' => 50, 'unit_price' => 1])->assertUnprocessable()->assertJsonValidationErrors('source');
        $this->deleteJson("/api/invoice-items/{$item->id}")->assertUnprocessable()->assertJsonValidationErrors('source');

        $this->assertModelExists($item);
        $this->assertSame('150000.00', $item->fresh()->amount);
    }

    public function test_v2_endpoints_require_authentication(): void
    {
        $this->postJson('/api/billing-v2/rooms/1/generate', [])->assertUnauthorized();
        $this->postJson('/api/billing-v2/meters/1/checkpoints', [])->assertUnauthorized();
        $this->postJson('/api/billing-v2/contracts/1/checkout', [])->assertUnauthorized();
        $this->postJson('/api/billing-v2/contracts/1/deposits', [])->assertUnauthorized();
        $this->getJson('/api/billing-v2/rooms/1')->assertUnauthorized();
    }

    public function test_manager_cannot_write_owner_financial_settlement(): void
    {
        [$a, $room] = $this->fixture();
        $manager = User::factory()->create();
        $room->boardingHouse->managers()->attach($manager);
        $this->actingAs($manager, 'sanctum');
        config(['billing.v2_pilot_user_ids' => [$manager->id]]);

        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertNotFound();
        $this->postJson("/api/billing-v2/contracts/{$a->id}/checkout", [])->assertNotFound();

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('contract_terminations', 0);
    }

    public function test_missing_second_meter_close_leaves_no_partial_invoice_and_retry_succeeds(): void
    {
        [$a, $room, $meter] = $this->fixture();
        $water = $room->boardingHouse->services()->create(['name' => 'Water test', 'type' => 'WATER', 'billing_method' => 'PER_UNIT', 'base_price' => 10000, 'is_active' => true]);
        $a->agreedServices()->create(['service_id' => $water->id, 'service_name' => $water->name, 'service_type' => 'WATER', 'billing_method' => 'PER_UNIT', 'base_price' => 10000, 'effective_from' => '2026-10-01', 'is_active' => true]);
        $other = $room->utilityMeters()->create(['service_id' => $water->id, 'is_active' => true]);
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-01 00:00:00', 150);
        $this->point($other->id, 'CHECK_IN', '2026-10-01 00:00:00', 0, $a->id);
        $this->point($other->id, 'PERIOD_BOUNDARY', '2026-10-01 00:00:00', 0);

        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertUnprocessable()->assertJsonValidationErrors('checkpoints');
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('utility_billing_periods', 0);
        $this->point($other->id, 'PERIOD_BOUNDARY', '2026-11-01 00:00:00', 5);
        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk();

        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_items', 2);
        $this->assertSame('200000.00', $a->invoices()->firstOrFail()->total_amount);
    }

    public function test_deactivated_meter_with_period_evidence_is_not_silently_omitted(): void
    {
        [, $room, $meter] = $this->fixture();
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-01 00:00:00', 150);
        $meter->update(['is_active' => false]);
        $meter->delete();

        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonPath('data.meters.0.quantity', '50.00');

        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseHas('invoice_items', ['quantity' => 50, 'amount' => 150000]);
    }

    public function test_abandonment_does_not_retroactively_waive_a_previous_months_unbilled_usage(): void
    {
        [$a, $room, $meter] = $this->fixture();
        $a->update(['start_date' => '2026-09-01']);
        $this->point($meter->id, 'CHECK_IN', '2026-09-01 00:00:00', 50, $a->id);
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-09-01 00:00:00', 50);
        $this->leave($a, $meter->id, '2026-10-15 10:00:00', 130, 'TENANT_ABANDONMENT');

        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-09-01', 'usage_start_at' => '2026-09-01 00:00:00', 'usage_end_at' => '2026-10-01 00:00:00'])->assertOk();

        $this->assertDatabaseHas('utility_charge_allocations', ['contract_id' => $a->id, 'responsible_party' => 'TENANT', 'quantity' => 50, 'tariff_amount' => 150000]);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_each_sequential_contract_invoice_can_be_issued_without_changing_its_siblings(): void
    {
        [$a, $room, $meter] = $this->fixture('TIERED');
        $this->leave($a, $meter->id, '2026-10-15 10:00:00', 130);
        $b = $this->next($a, $meter->id, '2026-10-15 10:00:00', 130);
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-01 00:00:00', 150);
        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk();
        $first = $a->invoices()->firstOrFail();
        $second = $b->invoices()->firstOrFail();

        $this->postJson("/api/billing-v2/invoices/{$first->id}/issue")->assertOk();
        $this->postJson("/api/billing-v2/invoices/{$second->id}/issue")->assertOk();
        $issued = $first->fresh()->getAttributes();
        $this->postJson("/api/billing-v2/invoices/{$first->id}/issue")->assertOk();

        $this->assertSame('UNPAID', $first->fresh()->status);
        $this->assertSame('UNPAID', $second->fresh()->status);
        $this->assertSame('96000.00', $first->fresh()->total_amount);
        $this->assertSame('64000.00', $second->fresh()->total_amount);
        $this->assertSame($issued, $first->fresh()->getAttributes());
    }

    public function test_changed_checkpoint_blocks_issue_until_draft_has_been_recalculated(): void
    {
        [$a, $room, $meter] = $this->fixture();
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-01 00:00:00', 150);
        $this->postJson("/api/billing-v2/boarding-houses/{$room->boarding_house_id}/monthly-invoices", ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonPath('data.0.status', 'CREATED');
        $invoice = $a->invoices()->firstOrFail();
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-01 00:00:00', 160);

        $this->postJson("/api/billing-v2/invoices/{$invoice->id}/issue")->assertUnprocessable()->assertJsonValidationErrors('billing_period');
        $this->assertSame('DRAFT', $invoice->fresh()->status);
        $this->assertSame('3950000.00', $invoice->fresh()->total_amount);
        $this->postJson("/api/invoices/{$invoice->id}/generate")->assertUnprocessable()->assertJsonValidationErrors('invoice_id');
        $prefix = "/api/billing-v2/rooms/{$room->id}/monthly-recalculations";
        $preview = $this->postJson($prefix.'/preview', ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonPath('data.status', 'PREVIEWED');
        $this->postJson($prefix.'/'.$preview->json('data.token').'/confirm', ['confirmed' => true, 'reason' => 'Đối soát chỉ số mới nhất'])->assertOk();
        $this->postJson("/api/billing-v2/invoices/{$invoice->id}/issue")->assertOk();

        $this->assertSame('UNPAID', $invoice->fresh()->status);
        $this->assertSame('3980000.00', $invoice->fresh()->total_amount);
    }

    public function test_legacy_update_cannot_issue_stale_v2_or_delete_its_financial_evidence(): void
    {
        [$a, $room, $meter] = $this->fixture();
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-01 00:00:00', 150);
        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk();
        $invoice = $a->invoices()->firstOrFail();

        $this->putJson("/api/invoices/{$invoice->id}", ['contract_id' => $a->id, 'billing_period' => '2026-10-01', 'status' => 'UNPAID'])->assertUnprocessable()->assertJsonValidationErrors('billing_period');
        $this->deleteJson("/api/invoices/{$invoice->id}")->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->assertSame('DRAFT', $invoice->fresh()->status);
        $this->assertDatabaseCount('utility_charge_allocations', 1);
    }

    public function test_legacy_status_transition_requires_checkout_for_a_v2_contract(): void
    {
        [$a] = $this->fixture();

        $this->putJson("/api/contracts/{$a->id}/status", ['status' => 'TERMINATED', 'move_out_date' => '2026-10-15'])->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->assertSame('ACTIVE', $a->fresh()->status);
        $this->assertDatabaseCount('contract_terminations', 0);
    }

    public function test_moved_out_primary_without_checkout_does_not_get_charged_to_month_end(): void
    {
        [$a, $room, $meter] = $this->fixture();
        $a->occupants()->create(['room_id' => $room->id, 'tenant_id' => $a->tenant_id, 'is_primary' => true, 'status' => 'MOVED_OUT', 'move_in_date' => '2026-10-01', 'move_out_date' => '2026-10-15']);
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-01 00:00:00', 150);

        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertUnprocessable()->assertJsonValidationErrors('checkpoints');

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_unconfirmed_legacy_tariff_is_not_silently_replaced_with_current_room_price(): void
    {
        [$a, $room, $meter] = $this->fixture();
        $a->service_terms_version = null;
        $a->save();
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-01 00:00:00', 150);

        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertUnprocessable()->assertJsonValidationErrors('tariff_conflict');

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_gap_in_agreed_tariff_does_not_charge_uncovered_days_using_another_price(): void
    {
        [$a, $room, $meter] = $this->fixture();
        $a->agreedServices()->update(['effective_to' => '2026-10-10']);
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-01 00:00:00', 150);

        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertUnprocessable()->assertJsonValidationErrors('tariff_conflict');

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_explicit_manual_rent_and_service_lines_share_v2_invoice_survive_regeneration_and_can_be_paid(): void
    {
        [$a, $room, $meter] = $this->fixture();
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-01 00:00:00', 150);
        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk();
        $invoice = $a->invoices()->firstOrFail();
        foreach ([['RENT', 'Rent explicitly confirmed by owner', 1, 3800000], ['INTERNET', 'Fixed charge explicitly confirmed by owner', 1, 120000], ['WATER', 'Person charge explicitly confirmed by owner', 2, 100000]] as [$type, $description, $quantity, $price]) {
            $this->postJson("/api/invoices/{$invoice->id}/items", ['type' => $type, 'description' => $description, 'quantity' => $quantity, 'unit_price' => $price])->assertCreated();
        }

        $this->postJson("/api/invoices/{$invoice->id}/generate")->assertUnprocessable()->assertJsonValidationErrors('invoice_id');
        $this->assertSame('4270000.00', $invoice->fresh()->total_amount);
        $this->assertSame(3, $invoice->items()->where('source', 'MANUAL')->count());
        $this->assertSame(1, $invoice->items()->where('source', 'AUTO')->count());
        $this->postJson("/api/billing-v2/invoices/{$invoice->id}/issue")->assertOk();
        $this->postJson("/api/invoices/{$invoice->id}/payments", ['amount' => 1000000, 'payment_method' => 'CASH', 'paid_at' => '2026-11-02 10:00:00', 'reference_code' => 'real-test-receipt-1'])->assertCreated();
        $this->assertSame('PARTIALLY_PAID', $invoice->fresh()->status);
        $this->postJson("/api/invoices/{$invoice->id}/payments", ['amount' => 3270000, 'payment_method' => 'CASH', 'paid_at' => '2026-11-02 11:00:00', 'reference_code' => 'real-test-receipt-2'])->assertCreated();

        $this->assertSame('PAID', $invoice->fresh()->status);
        $this->assertSame('4270000.00', $invoice->fresh()->paid_amount);
        $this->postJson("/api/invoices/{$invoice->id}/items", ['type' => 'OTHER', 'description' => 'Cannot change issued amount', 'quantity' => 1, 'unit_price' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('invoice_items', 4);
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_legacy_full_month_auto_rent_fixed_person_and_utility_rules_are_preserved(): void
    {
        [$a, $room, $meter, $electricity] = $this->fixture();
        $a->occupants()->create(['room_id' => $room->id, 'tenant_id' => $a->tenant_id, 'is_primary' => true, 'status' => 'ACTIVE', 'move_in_date' => '2026-10-01']);
        $other = Tenant::create(['landlord_id' => $room->boardingHouse->landlord_id, 'full_name' => 'Synthetic second resident']);
        $a->occupants()->create(['room_id' => $room->id, 'tenant_id' => $other->id, 'is_primary' => false, 'status' => 'ACTIVE', 'move_in_date' => '2026-10-01']);
        foreach ([['INTERNET', 'FIXED', 120000], ['WATER', 'PER_PERSON', 100000]] as [$type, $method, $price]) {
            $service = $room->boardingHouse->services()->create(['name' => 'Synthetic '.$method, 'type' => $type, 'billing_method' => $method, 'base_price' => $price, 'is_active' => true]);
            $room->services()->attach($service, ['is_active' => true]);
            $a->agreedServices()->create(['service_id' => $service->id, 'service_name' => $service->name, 'service_type' => $type, 'billing_method' => $method, 'base_price' => $price, 'effective_from' => '2026-10-01', 'is_active' => true]);
        }
        $a->utilityOpenings()->create(['utility_meter_id' => $meter->id, 'service_id' => $electricity->id, 'service_name' => $electricity->name, 'opening_reading' => 100, 'reading_date' => '2026-10-01']);
        $meter->readings()->create(['reading_date' => '2026-10-31', 'reading_value' => 150]);
        $invoice = $a->invoices()->create(['room_id' => $room->id, 'billing_period' => '2026-10-01', 'status' => 'DRAFT']);
        $this->postJson("/api/invoices/{$invoice->id}/items", ['type' => 'OTHER', 'description' => 'Synthetic manual charge', 'quantity' => 1, 'unit_price' => 70000])->assertCreated();

        $this->postJson("/api/invoices/{$invoice->id}/generate")->assertOk();

        $this->assertSame('4340000.00', $invoice->fresh()->total_amount);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'RENT', 'amount' => 3800000, 'source' => 'AUTO']);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'INTERNET', 'amount' => 120000, 'source' => 'AUTO']);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'WATER', 'quantity' => 2, 'amount' => 200000, 'source' => 'AUTO']);
        $before = $invoice->fresh()->getAttributes();
        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertUnprocessable()->assertJsonValidationErrors('billing_period');
        $this->assertSame($before, $invoice->fresh()->getAttributes());
        $this->assertDatabaseCount('invoice_items', 5);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_pending_payment_request_blocks_v2_recalculation_and_issue_without_mutating_evidence(): void
    {
        [$a, $room, $meter] = $this->fixture();
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-01 00:00:00', 150);
        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk();
        $invoice = $a->invoices()->firstOrFail();
        $request = $invoice->payosPaymentRequests()->create(['amount' => 150000, 'status' => 'PENDING']);
        $snapshot = DB::table('utility_billing_periods')->value('snapshot');

        $this->postJson("/api/invoices/{$invoice->id}/generate")->assertUnprocessable();
        $this->postJson("/api/billing-v2/invoices/{$invoice->id}/issue")->assertUnprocessable();

        $this->assertSame($snapshot, DB::table('utility_billing_periods')->value('snapshot'));
        $this->assertSame('DRAFT', $invoice->fresh()->status);
        $this->assertSame('150000.00', $invoice->fresh()->total_amount);
        $this->assertSame('PENDING', $request->fresh()->status);
        $this->assertDatabaseCount('invoice_items', 1);
    }

    public function test_confirmed_abandonment_forfeits_actual_three_million_and_excludes_all_new_auto_components(): void
    {
        [$a, $room, $meter] = $this->fixture('TIERED');
        foreach (['FIXED', 'PER_PERSON', 'PER_UNIT', 'TIERED'] as $method) {
            $service = $room->boardingHouse->services()->create(['name' => 'Synthetic '.$method, 'type' => 'OTHER', 'billing_method' => $method, 'base_price' => 100000, 'is_active' => true]);
            $room->services()->attach($service, ['is_active' => true]);
            $a->agreedServices()->create(['service_id' => $service->id, 'service_name' => $service->name, 'service_type' => 'OTHER', 'billing_method' => $method, 'base_price' => 100000, 'effective_from' => '2026-10-01', 'is_active' => true]);
        }
        $oldDebt = $a->invoices()->create(['room_id' => $room->id, 'billing_period' => '2026-09-01', 'status' => 'UNPAID', 'subtotal' => 100000, 'total_amount' => 100000]);
        $before = $oldDebt->fresh()->getAttributes();
        $this->postJson("/api/billing-v2/contracts/{$a->id}/deposits", ['event' => 'RECEIVED', 'amount' => 3000000, 'request_key' => 'actual-three-million', 'occurred_at' => '2026-10-01 00:00:00'])->assertOk();

        $this->leave($a, $meter->id, '2026-10-20 10:00:00', 130, 'TENANT_ABANDONMENT');

        $this->assertSame('TERMINATED', $a->fresh()->status);
        $this->assertDatabaseHas('contract_deposit_entries', ['contract_id' => $a->id, 'event' => 'FORFEITED', 'amount' => 3000000]);
        $case = DB::table('contract_settlements')->where('contract_id', $a->id)->first();
        $this->assertSame('NO_NEW_AUTO_RECEIVABLES', $case->collection_policy);
        $this->assertSame('CONFIRMED', $case->confirmation_state);
        $this->assertSame(['RENT', 'FIXED', 'PER_PERSON', 'PER_UNIT', 'TIERED', 'OTHER_AUTO_SERVICES'], json_decode($case->policy_snapshot, true)['waived_auto_components']);
        $this->assertDatabaseHas('contract_settlement_usages', ['contract_settlement_id' => $case->id, 'checkout_period_quantity' => null, 'status' => 'PENDING_FINAL_SETTLEMENT', 'tariff_amount' => null, 'responsible_party' => 'LANDLORD']);
        $this->assertSame(0, $a->invoices()->whereDate('billing_period', '2026-10-01')->count());
        $b = $this->next($a, $meter->id, '2026-10-25 10:00:00', 135);
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-01 00:00:00', 150);
        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk();

        $this->assertSame(0, $a->invoices()->whereDate('billing_period', '2026-10-01')->count());
        $this->assertSame('48000.00', $b->invoices()->firstOrFail()->total_amount);
        $this->assertSame($before, $oldDebt->fresh()->getAttributes());
        $this->assertDatabaseHas('contract_settlement_usages', ['contract_settlement_id' => $case->id, 'final_period_quantity' => 30, 'tariff_amount' => 96000, 'status' => 'FINAL_RECONCILED']);
        $this->assertDatabaseCount('payments', 0);
    }

    #[TestWith(['MUTUAL_EARLY_END'])]
    #[TestWith(['NORMAL_EXPIRY'])]
    public function test_normal_or_agreed_checkout_records_pending_tiered_quantity_without_waiving_receivables(string $reason): void
    {
        [$a, $room, $meter] = $this->fixture('TIERED');
        $a->update(['end_date' => '2026-10-20']);

        $this->leave($a, $meter->id, '2026-10-20 10:00:00', 130, $reason);

        $case = DB::table('contract_settlements')->where('contract_id', $a->id)->first();
        $this->assertSame('AGREEMENT_REQUIRED', $case->collection_policy);
        $this->assertSame('PENDING_FINAL_SETTLEMENT', $case->status);
        $this->assertDatabaseHas('contract_settlement_usages', ['contract_settlement_id' => $case->id, 'checkout_period_quantity' => null, 'tariff_amount' => null, 'responsible_party' => 'TENANT']);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('contract_deposit_entries', 0);
        $this->getJson("/api/billing-v2/rooms/{$room->id}?billing_period=2026-10-01")->assertOk()->assertJsonPath('data.settlements.0.status', 'PENDING_FINAL_SETTLEMENT')->assertJsonPath('data.settlement_usages.0.tariff_amount', null);
    }

    public function test_abandonment_without_actual_held_deposit_needs_owner_confirmation_and_never_invents_forfeiture(): void
    {
        [$a, $room, $meter] = $this->fixture('TIERED');
        $this->postJson("/api/billing-v2/contracts/{$a->id}/checkout", ['actual_ended_at' => '2026-10-20 10:00:00', 'reason' => 'TENANT_ABANDONMENT', 'readings' => [['utility_meter_id' => $meter->id, 'reading_value' => 130]]])->assertOk();
        $this->point($meter->id, 'PERIOD_BOUNDARY', '2026-11-01 00:00:00', 150);

        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertUnprocessable()->assertJsonValidationErrors('settlement_confirmation');

        $this->assertSame('TERMINATED', $a->fresh()->status);
        $this->assertDatabaseHas('contract_settlements', ['contract_id' => $a->id, 'confirmation_state' => 'NEEDS_OWNER_CONFIRMATION']);
        $this->assertDatabaseCount('contract_deposit_entries', 0);
        $this->postJson("/api/billing-v2/contracts/{$a->id}/settlement-confirmation", ['confirm_no_held_deposit' => true, 'reason' => 'Owner verified there is no held cash deposit.'])->assertOk();
        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk();
        $this->assertDatabaseHas('contract_settlements', ['contract_id' => $a->id, 'deposit_status' => 'NO_HELD_DEPOSIT_CONFIRMED', 'status' => 'FINAL_RECONCILED']);
        $this->assertDatabaseCount('contract_deposit_entries', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_existing_draft_manual_and_auto_charges_are_flagged_for_review_not_deleted_by_abandonment(): void
    {
        [$a, $room, $meter] = $this->fixture();
        $invoice = $a->invoices()->create(['room_id' => $room->id, 'billing_period' => '2026-10-01', 'status' => 'DRAFT']);
        $manual = $invoice->items()->create(['type' => 'RENT', 'description' => 'Already confirmed manual rent', 'quantity' => 1, 'unit_price' => 3800000, 'amount' => 3800000, 'source' => 'MANUAL']);
        $invoice->items()->create(['type' => 'INTERNET', 'description' => 'Previously generated fee', 'quantity' => 1, 'unit_price' => 120000, 'amount' => 120000, 'source' => 'AUTO']);
        $invoice->refresh();
        $invoice->recalculateTotals();
        $before = $invoice->fresh()->getAttributes();
        $this->leave($a, $meter->id, '2026-10-20 10:00:00', 130, 'TENANT_ABANDONMENT');

        $this->postJson("/api/invoices/{$invoice->id}/generate")->assertUnprocessable()->assertJsonValidationErrors('settlement_review');
        $this->deleteJson("/api/invoice-items/{$manual->id}")->assertUnprocessable()->assertJsonValidationErrors('settlement_review');
        $this->postJson("/api/invoices/{$invoice->id}/items", ['type' => 'OTHER', 'description' => 'No new fee for A', 'quantity' => 1, 'unit_price' => 100])->assertUnprocessable()->assertJsonValidationErrors('settlement_review');
        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertUnprocessable()->assertJsonValidationErrors('settlement_review');

        $this->assertSame($before, $invoice->fresh()->getAttributes());
        $this->assertDatabaseCount('invoice_items', 2);
        $this->assertDatabaseHas('contract_settlements', ['contract_id' => $a->id, 'invoice_review_status' => 'NEEDS_INVOICE_REVIEW']);
    }

    public function test_new_contract_can_activate_while_previous_tiered_settlement_is_pending(): void
    {
        [$a, $room, $meter, $service] = $this->fixture('TIERED');
        $this->leave($a, $meter->id, '2026-10-20 10:00:00', 130, 'TENANT_ABANDONMENT');
        $b = Contract::factory()->create(['room_id' => $room->id, 'start_date' => '2026-10-25', 'status' => 'DRAFT']);
        $b->handoverReports()->create(['type' => 'CHECK_IN', 'status' => 'CONFIRMED', 'items' => [['name' => 'Synthetic handover', 'quantity' => 1]], 'confirmed_at' => now()]);

        $this->putJson("/api/contracts/{$b->id}", ['status' => 'ACTIVE', 'lessor_representative_user_id' => $room->boardingHouse->landlord->user_id,
            'utility_openings' => [['utility_meter_id' => $meter->id, 'opening_reading' => 135, 'reading_date' => '2026-10-25']], 'services' => [['service_id' => $service->id]]])->assertOk();

        $this->assertSame('ACTIVE', $b->fresh()->status);
        $this->assertDatabaseHas('contract_settlements', ['contract_id' => $a->id, 'status' => 'PENDING_FINAL_SETTLEMENT']);
        $this->assertDatabaseCount('utility_billing_periods', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_other_landlord_cannot_confirm_abandonment_settlement(): void
    {
        [$a] = $this->fixture();
        $otherUser = User::factory()->create();
        $this->actingAs($otherUser, 'sanctum');
        config(['billing.v2_pilot_user_ids' => [$otherUser->id]]);

        $this->postJson("/api/billing-v2/contracts/{$a->id}/settlement-confirmation", ['confirm_no_held_deposit' => true, 'reason' => 'Foreign user must not confirm.'])->assertNotFound();

        $this->assertDatabaseCount('contract_settlements', 0);
    }

    public function test_abandonment_keeps_previously_issued_and_paid_current_cycle_invoice_unchanged(): void
    {
        [$a, $room, $meter] = $this->fixture();
        $invoice = $a->invoices()->create(['room_id' => $room->id, 'billing_period' => '2026-10-01', 'status' => 'UNPAID', 'issued_at' => '2026-10-01', 'subtotal' => 100000, 'total_amount' => 100000]);
        $invoice->items()->create(['type' => 'RENT', 'description' => 'Previously issued agreed charge', 'quantity' => 1, 'unit_price' => 100000, 'amount' => 100000, 'source' => 'AUTO']);
        $this->postJson("/api/invoices/{$invoice->id}/payments", ['amount' => 100000, 'payment_method' => 'CASH', 'paid_at' => '2026-10-10 10:00:00'])->assertCreated();
        $before = $invoice->fresh()->getAttributes();
        $this->leave($a, $meter->id, '2026-10-20 10:00:00', 130, 'TENANT_ABANDONMENT');

        $this->postJson("/api/invoices/{$invoice->id}/generate")->assertUnprocessable()->assertJsonValidationErrors('settlement_review');
        $this->postJson("/api/billing-v2/rooms/{$room->id}/generate", ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertUnprocessable()->assertJsonValidationErrors('settlement_review');

        $this->assertSame($before, $invoice->fresh()->getAttributes());
        $this->assertSame('PAID', $invoice->fresh()->status);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('invoice_items', 1);
        $this->assertDatabaseHas('contract_settlements', ['contract_id' => $a->id, 'invoice_review_status' => 'NEEDS_INVOICE_REVIEW']);
    }
}
