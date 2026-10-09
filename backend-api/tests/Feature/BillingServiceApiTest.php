<?php

namespace Tests\Feature;

use App\Models\BoardingHouse;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Landlord;
use App\Models\Room;
use App\Models\RoomTenant;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UtilityMeter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BillingServiceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unassigned_house_catalogue_services_are_not_charged_and_manual_items_are_preserved(): void
    {
        [$user, $room] = $this->ownerAndRoom();
        $this->service($room, 'ELECTRICITY', 'PER_UNIT', 3_500);
        $this->service($room, 'WATER', 'FIXED', 100_000);
        $invoice = $this->invoice($room);
        $manual = $invoice->items()->create([
            'type' => 'OTHER', 'description' => 'Khoản thu thủ công',
            'quantity' => 1, 'unit_price' => 25_000, 'amount' => 25_000, 'source' => 'MANUAL',
        ]);

        $this->generate($user, $invoice)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Invoice generated successfully.')
            ->assertJsonPath('data', null);

        $this->assertDatabaseCount('invoice_items', 1);
        $this->assertModelExists($manual);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'subtotal' => 25_000, 'total_amount' => 25_000]);
        $this->assertDatabaseCount('utility_meters', 0);
    }

    public function test_fixed_electricity_and_water_are_charged_without_meters(): void
    {
        [$user, $room] = $this->ownerAndRoom();
        $electricity = $this->service($room, 'ELECTRICITY', 'FIXED', 350_000);
        $water = $this->service($room, 'WATER', 'FIXED', 100_000);
        $this->assign($room, $electricity, $water);
        $invoice = $this->invoice($room);

        $this->generate($user, $invoice)->assertOk()->assertJsonPath('data', null);

        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'ELECTRICITY', 'quantity' => 1, 'unit_price' => 350_000, 'amount' => 350_000, 'source' => 'AUTO']);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'WATER', 'quantity' => 1, 'amount' => 100_000]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'subtotal' => 450_000, 'total_amount' => 450_000]);
        $this->assertDatabaseCount('utility_meters', 0);
    }

    public function test_service_applied_to_another_room_in_the_same_house_is_not_charged(): void
    {
        [$user, $room] = $this->ownerAndRoom();
        $otherRoom = Room::create(['boarding_house_id' => $room->boarding_house_id, 'room_code' => '102', 'monthly_rent' => 3_000_000, 'status' => 'AVAILABLE']);
        $water = $this->service($room, 'WATER', 'FIXED', 100_000);
        $this->assign($otherRoom, $water);
        $invoice = $this->invoice($room);

        $this->generate($user, $invoice)->assertOk();

        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'total_amount' => 0]);
    }

    public function test_per_person_water_charges_only_active_occupants_in_the_billing_period(): void
    {
        [$user, $room] = $this->ownerAndRoom();
        $water = $this->service($room, 'WATER', 'PER_PERSON', 50_000);
        $this->assign($room, $water);
        $this->occupant($room, ['move_in_date' => '2026-09-01']);
        $this->occupant($room, ['move_in_date' => '2026-10-10']);
        $this->occupant($room, ['status' => 'MOVED_OUT', 'move_out_date' => '2026-09-30']);
        $this->occupant($room, ['move_in_date' => '2026-11-01']);
        $invoice = $this->invoice($room);

        $this->generate($user, $invoice)->assertOk();

        $this->assertDatabaseCount('invoice_items', 1);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'WATER', 'quantity' => 2, 'unit_price' => 50_000, 'amount' => 100_000]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'total_amount' => 100_000]);
        $this->assertDatabaseCount('utility_meters', 0);
    }

    public function test_per_unit_electricity_and_water_each_require_a_real_active_meter_and_roll_back_on_422(): void
    {
        [$user, $room] = $this->ownerAndRoom();
        $electricity = $this->service($room, 'ELECTRICITY', 'PER_UNIT', 3_500);
        $water = $this->service($room, 'WATER', 'PER_UNIT', 20_000);
        $this->assign($room, $electricity, $water);
        $meter = $this->meter($room, $electricity, 100);
        $this->reading($meter, '2026-10-31', 120);
        $invoice = $this->invoice($room);

        $this->generate($user, $invoice)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('service_id')
            ->assertJsonPath('errors.service_id.0', 'An active utility meter is required.');

        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'subtotal' => 0, 'total_amount' => 0]);
        $this->assertDatabaseCount('utility_meters', 1);
    }

    public function test_per_unit_services_use_previous_readings_and_each_services_price(): void
    {
        [$user, $room] = $this->ownerAndRoom();
        $electricity = $this->service($room, 'ELECTRICITY', 'PER_UNIT', 3_500);
        $water = $this->service($room, 'WATER', 'PER_UNIT', 20_000);
        $this->assign($room, $electricity, $water);
        $electricityMeter = $this->meter($room, $electricity, 100);
        $waterMeter = $this->meter($room, $water, 10);
        $this->reading($electricityMeter, '2026-08-31', 115);
        $this->reading($electricityMeter, '2026-09-30', 120);
        $this->reading($electricityMeter, '2026-10-31', 140);
        $this->reading($waterMeter, '2026-08-31', 11);
        $this->reading($waterMeter, '2026-09-30', 12);
        $this->reading($waterMeter, '2026-10-31', 15);
        $invoice = $this->invoice($room);

        $this->generate($user, $invoice)->assertOk();

        $this->assertDatabaseCount('invoice_items', 2);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'ELECTRICITY', 'quantity' => 20, 'unit_price' => 3_500, 'amount' => 70_000]);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'WATER', 'quantity' => 3, 'unit_price' => 20_000, 'amount' => 60_000]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'total_amount' => 130_000]);
    }

    public function test_meter_without_readings_returns_422_without_generating_items(): void
    {
        [$user, $room] = $this->ownerAndRoom();
        $water = $this->service($room, 'WATER', 'PER_UNIT', 20_000);
        $this->assign($room, $water);
        $this->meter($room, $water);
        $invoice = $this->invoice($room);

        $this->generate($user, $invoice)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reading_date')
            ->assertJsonPath('errors.reading_date.0', 'A current utility reading is required.');

        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'total_amount' => 0]);
    }

    public function test_tiered_service_uses_its_price_tiers_and_initial_reading(): void
    {
        [$user, $room] = $this->ownerAndRoom();
        $electricity = $this->service($room, 'ELECTRICITY', 'TIERED', null);
        $this->assign($room, $electricity);
        $electricity->priceTiers()->createMany([
            ['from_quantity' => 0, 'to_quantity' => 50, 'unit_price' => 1_900, 'tier_order' => 1],
            ['from_quantity' => 50, 'to_quantity' => 100, 'unit_price' => 2_000, 'tier_order' => 2],
            ['from_quantity' => 100, 'to_quantity' => null, 'unit_price' => 2_500, 'tier_order' => 3],
        ]);
        $meter = $this->meter($room, $electricity, 100);
        $this->reading($meter, '2026-10-31', 220);
        $invoice = $this->invoice($room);

        $this->generate($user, $invoice)->assertOk();

        $this->assertDatabaseCount('invoice_items', 1);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'ELECTRICITY', 'quantity' => 120, 'amount' => 245_000]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'total_amount' => 245_000]);
    }

    public function test_inactive_assignments_and_globally_disabled_services_are_not_billed(): void
    {
        [$user, $room] = $this->ownerAndRoom();
        $disabledAssignment = $this->service($room, 'WATER', 'PER_UNIT', 20_000);
        $disabledCatalogue = $this->service($room, 'ELECTRICITY', 'PER_UNIT', 3_500);
        $disabledCatalogue->update(['is_active' => false]);
        $active = $this->service($room, 'INTERNET', 'FIXED', 150_000);
        $this->assign($room, $disabledCatalogue, $active);
        $room->services()->attach($disabledAssignment->id, ['is_active' => false]);
        $invoice = $this->invoice($room);

        $this->generate($user, $invoice)->assertOk();

        $this->assertDatabaseCount('invoice_items', 1);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'INTERNET', 'amount' => 150_000]);
        $this->assertDatabaseMissing('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'WATER']);
        $this->assertDatabaseMissing('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'ELECTRICITY']);
    }

    public function test_switching_fixed_water_to_per_unit_affects_the_next_generation_not_existing_snapshots(): void
    {
        [$user, $room] = $this->ownerAndRoom();
        $fixed = $this->service($room, 'WATER', 'FIXED', 100_000);
        $perUnit = $this->service($room, 'WATER', 'PER_UNIT', 20_000);
        $this->assign($room, $fixed);
        $oldInvoice = $this->invoice($room);
        $this->generate($user, $oldInvoice)->assertOk();
        $oldItem = $oldInvoice->items()->sole();

        $this->withToken($user->createToken('test')->plainTextToken)
            ->putJson("/api/rooms/{$room->id}/services", ['service_ids' => [$perUnit->id]])
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertDatabaseHas('room_services', ['room_id' => $room->id, 'service_id' => $fixed->id, 'is_active' => false]);
        $this->assertDatabaseHas('room_services', ['room_id' => $room->id, 'service_id' => $perUnit->id, 'is_active' => true]);
        $this->assertDatabaseHas('invoice_items', ['id' => $oldItem->id, 'invoice_id' => $oldInvoice->id, 'amount' => 100_000, 'description' => $fixed->name]);
        $this->assertDatabaseHas('invoices', ['id' => $oldInvoice->id, 'total_amount' => 100_000]);
        $this->assertDatabaseCount('utility_meters', 0);

        $newInvoice = $this->invoice($room, '2026-11-01');
        $this->generate($user, $newInvoice)->assertUnprocessable()->assertJsonValidationErrors('service_id');
        $meter = $this->meter($room, $perUnit);
        $this->reading($meter, '2026-11-30', 3);

        $this->generate($user, $newInvoice)->assertOk();

        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $newInvoice->id, 'type' => 'WATER', 'quantity' => 3, 'unit_price' => 20_000, 'amount' => 60_000]);
        $this->assertDatabaseHas('invoices', ['id' => $newInvoice->id, 'total_amount' => 60_000]);
        $this->assertDatabaseHas('invoice_items', ['id' => $oldItem->id, 'amount' => 100_000, 'description' => $fixed->name]);
        $this->assertDatabaseHas('invoices', ['id' => $oldInvoice->id, 'total_amount' => 100_000]);
    }

    public function test_rent_from_contract_still_generates_when_room_has_no_services(): void
    {
        [$user, $room] = $this->ownerAndRoom();
        $tenant = Tenant::create(['landlord_id' => $room->boardingHouse->landlord_id, 'full_name' => 'Nguyễn Văn A']);
        $contract = Contract::create([
            'room_id' => $room->id, 'tenant_id' => $tenant->id, 'contract_code' => 'HD-101',
            'start_date' => '2026-09-01', 'end_date' => '2027-08-31',
            'monthly_rent' => 3_000_000, 'deposit_amount' => 0, 'status' => 'ACTIVE',
        ]);
        $invoice = $this->invoice($room);
        $invoice->update(['contract_id' => $contract->id]);

        $this->generate($user, $invoice)->assertOk();

        $this->assertDatabaseCount('invoice_items', 1);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'RENT', 'quantity' => 1, 'amount' => 3_000_000]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'total_amount' => 3_000_000]);
    }

    private function ownerAndRoom(): array
    {
        $user = User::factory()->create();
        $landlord = Landlord::create(['user_id' => $user->id, 'full_name' => $user->name, 'phone' => '0900000000']);
        $house = BoardingHouse::create(['landlord_id' => $landlord->id, 'name' => 'Nhà trọ', 'address' => 'Địa chỉ']);
        $room = Room::create(['boarding_house_id' => $house->id, 'room_code' => '101', 'monthly_rent' => 3_000_000, 'status' => 'AVAILABLE']);

        return [$user, $room];
    }

    private function invoice(Room $room, string $period = '2026-10-01'): Invoice
    {
        return $room->invoices()->create([
            'invoice_code' => 'INV-'.Invoice::count(), 'billing_period' => $period,
            'subtotal' => 0, 'discount_amount' => 0, 'total_amount' => 0,
            'paid_amount' => 0, 'status' => 'DRAFT',
        ]);
    }

    private function service(Room $room, string $type, string $method, ?int $price): Service
    {
        return Service::create([
            'boarding_house_id' => $room->boarding_house_id,
            'name' => $type.' '.$method, 'type' => $type, 'billing_method' => $method,
            'base_price' => $price, 'unit' => $type === 'ELECTRICITY' ? 'kWh' : 'm³', 'is_active' => true,
        ]);
    }

    private function assign(Room $room, Service ...$services): void
    {
        foreach ($services as $service) {
            $room->services()->attach($service->id, ['is_active' => true]);
        }
    }

    private function meter(Room $room, Service $service, int $initial = 0): UtilityMeter
    {
        return UtilityMeter::create([
            'room_id' => $room->id, 'service_id' => $service->id,
            'initial_reading' => $initial, 'is_active' => true,
        ]);
    }

    private function reading(UtilityMeter $meter, string $date, int $value): void
    {
        $meter->readings()->create(['reading_date' => $date, 'reading_value' => $value]);
    }

    private function occupant(Room $room, array $attributes = []): void
    {
        $tenant = Tenant::create(['landlord_id' => $room->boardingHouse->landlord_id, 'full_name' => 'Người thuê '.Tenant::count()]);
        RoomTenant::create([
            'room_id' => $room->id, 'tenant_id' => $tenant->id,
            'status' => 'ACTIVE', 'move_in_date' => '2026-09-01', ...$attributes,
        ]);
    }

    private function generate(User $user, Invoice $invoice): TestResponse
    {
        return $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson("/api/invoices/{$invoice->id}/generate");
    }
}
