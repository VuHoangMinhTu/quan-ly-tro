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
            ->assertJsonPath('message', 'Tạo các khoản thu tự động thành công.')
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

    public function test_assigned_fixed_service_without_price_fails_instead_of_being_silently_skipped(): void
    {
        [$user, $room] = $this->ownerAndRoom();
        $service = $this->service($room, 'OTHER', 'FIXED', null);
        $this->assign($room, $service);
        $invoice = $this->invoice($room);

        $this->generate($user, $invoice)->assertUnprocessable()
            ->assertJsonPath('errors.service_id.0', 'Dịch vụ OTHER FIXED chưa có đơn giá. Vui lòng cập nhật trước khi tạo khoản thu.');

        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'total_amount' => 0]);
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

    public function test_live_per_person_service_counts_only_the_invoiced_contract_not_previous_room_contracts(): void
    {
        [$user, $room] = $this->ownerAndRoom();
        $current = Contract::factory()->create(['room_id' => $room->id, 'status' => 'ACTIVE', 'start_date' => '2026-10-23']);
        $current->forceFill(['service_terms_version' => 1, 'signing_snapshot' => ['services' => []]])->save();
        $old = Contract::factory()->create(['room_id' => $room->id, 'status' => 'TERMINATED', 'start_date' => '2026-09-01', 'end_date' => '2026-10-22']);
        $this->occupant($room, ['contract_id' => $old->id, 'status' => 'MOVED_OUT', 'move_out_date' => '2026-10-22']);
        $current->occupants()->create(['room_id' => $room->id, 'tenant_id' => $current->tenant_id, 'is_primary' => true, 'status' => 'ACTIVE', 'move_in_date' => '2026-10-23']);
        $water = $this->service($room, 'WATER', 'PER_PERSON', 150000);
        $this->assign($room, $water);
        $invoice = $this->invoice($room);
        $invoice->update(['contract_id' => $current->id]);

        $this->generate($user, $invoice)->assertOk();

        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'WATER', 'quantity' => 1, 'unit' => 'người', 'amount' => 150000]);
        $this->assertDatabaseHas('room_tenants', ['contract_id' => $old->id, 'status' => 'MOVED_OUT']);
    }

    public function test_per_person_rejects_unlinked_legacy_occupancy_instead_of_guessing_and_preserves_existing_items(): void
    {
        [$user, $room] = $this->ownerAndRoom();
        $contract = Contract::factory()->create(['room_id' => $room->id, 'status' => 'ACTIVE']);
        $contract->occupants()->create(['room_id' => $room->id, 'tenant_id' => $contract->tenant_id, 'is_primary' => true, 'status' => 'ACTIVE', 'move_in_date' => '2026-10-01']);
        $this->occupant($room);
        $water = $this->service($room, 'WATER', 'PER_PERSON', 150000);
        $this->assign($room, $water);
        $invoice = $this->invoice($room);
        $invoice->update(['contract_id' => $contract->id]);
        $item = $invoice->items()->create(['type' => 'WATER', 'description' => 'Khoản đã ghi nhận', 'quantity' => 2, 'unit_price' => 150000, 'amount' => 300000, 'source' => 'AUTO']);
        $invoice->recalculateTotals();
        $before = $invoice->fresh()->getAttributes();

        $this->generate($user, $invoice)->assertUnprocessable()->assertJsonValidationErrors('contract_id');

        $this->assertModelExists($item);
        $this->assertSame($before, $invoice->fresh()->getAttributes());
        $this->assertDatabaseCount('invoice_items', 1);
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
            ->assertJsonPath('errors.service_id.0', 'Dịch vụ Nước đang tính theo đơn vị nhưng phòng chưa có đồng hồ đang hoạt động cho dịch vụ này.');

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

    public function test_november_uses_october_opening_and_november_closing_but_not_december_reading(): void
    {
        [$user, $room] = $this->ownerAndRoom();
        $electricity = $this->service($room, 'ELECTRICITY', 'PER_UNIT', 3_800);
        $this->assign($room, $electricity);
        $meter = $this->meter($room, $electricity, 0);
        $this->reading($meter, '2026-10-25', 171);
        $this->reading($meter, '2026-11-25', 173);
        $this->reading($meter, '2026-12-05', 200);
        $invoice = $this->invoice($room, '2026-11-01');

        $this->generate($user, $invoice)->assertOk();

        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'ELECTRICITY', 'quantity' => 2,
            'unit' => 'kWh', 'unit_price' => 3_800, 'amount' => 7_600]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'total_amount' => 7_600]);
    }

    public function test_missing_november_closing_fails_atomically_even_when_december_has_a_reading(): void
    {
        [$user, $room] = $this->ownerAndRoom();
        $fixed = $this->service($room, 'OTHER', 'FIXED', 150_000);
        $electricity = $this->service($room, 'ELECTRICITY', 'PER_UNIT', 3_800);
        $this->assign($room, $fixed, $electricity);
        $meter = $this->meter($room, $electricity, 0);
        $this->reading($meter, '2026-10-25', 171);
        $this->reading($meter, '2026-12-05', 200);
        $invoice = $this->invoice($room, '2026-11-01');

        $this->generate($user, $invoice)->assertUnprocessable()
            ->assertJsonPath('errors.reading_date.0', 'ELECTRICITY PER_UNIT đã có chỉ số đầu kỳ nhưng chưa có chỉ số cuối kỳ đến ngày 30/11/2026. Vui lòng ghi chỉ số.');

        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'total_amount' => 0]);
    }

    public function test_contract_rent_and_all_assigned_room_services_are_billed_together(): void
    {
        [$user, $room] = $this->ownerAndRoom();
        $tenant = Tenant::create(['landlord_id' => $room->boardingHouse->landlord_id, 'full_name' => 'Người đứng tên']);
        $contract = Contract::create(['room_id' => $room->id, 'tenant_id' => $tenant->id, 'contract_code' => 'HD-FULL',
            'start_date' => '2026-10-01', 'end_date' => '2027-09-30', 'monthly_rent' => 3_000_000,
            'deposit_amount' => 0, 'status' => 'ACTIVE', 'service_terms_version' => 1]);
        RoomTenant::create(['room_id' => $room->id, 'tenant_id' => $contract->tenant_id, 'is_primary' => true, 'status' => 'ACTIVE', 'move_in_date' => '2026-10-01', 'contract_id' => $contract->id]);
        $electricity = $this->service($room, 'ELECTRICITY', 'PER_UNIT', 3_800);
        $water = $this->service($room, 'WATER', 'PER_PERSON', 150_000);
        $fixed = $this->service($room, 'OTHER', 'FIXED', 150_000);
        $this->assign($room, $electricity, $water, $fixed);
        $meter = $this->meter($room, $electricity);
        $contract->utilityOpenings()->create(['utility_meter_id' => $meter->id, 'service_id' => $electricity->id, 'service_name' => $electricity->name, 'opening_reading' => 0, 'reading_date' => '2026-10-01']);
        $this->reading($meter, '2026-10-25', 171);
        $this->reading($meter, '2026-11-25', 173);
        $invoice = $this->invoice($room, '2026-11-01');
        $invoice->update(['contract_id' => $contract->id]);

        $this->generate($user, $invoice)->assertOk();

        $this->assertDatabaseCount('invoice_items', 4);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'RENT', 'amount' => 3_000_000]);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'WATER', 'unit' => 'người', 'quantity' => 1, 'amount' => 150_000]);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'OTHER', 'unit' => 'tháng', 'amount' => 150_000]);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'ELECTRICITY', 'unit' => 'kWh', 'quantity' => 2, 'amount' => 7_600]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'total_amount' => 3_307_600]);
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
            ->assertJsonPath('errors.reading_date.0', 'WATER PER_UNIT đã có chỉ số đầu kỳ nhưng chưa có chỉ số cuối kỳ đến ngày 31/10/2026. Vui lòng ghi chỉ số.');

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
        RoomTenant::create(['room_id' => $room->id, 'tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'is_primary' => true, 'status' => 'ACTIVE', 'move_in_date' => '2026-09-01']);
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
