<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\RoomTenant;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UtilityMeter;
use App\Models\UtilityReading;
use App\Services\ContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ContractLifecycleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_contract_endpoints_require_authentication(): void
    {
        $this->getJson('/api/rooms/1/contract-context')->assertUnauthorized();
        $this->putJson('/api/contracts/1/status', ['status' => 'ACTIVE'])->assertUnauthorized();
        $this->putJson('/api/contracts/1/room', ['room_id' => 2])->assertUnauthorized();
        $this->putJson('/api/contracts/1/occupants/1/move-out', ['move_out_date' => '2026-10-15'])->assertUnauthorized();
        $this->putJson('/api/boarding-houses/1/contract-schedule', ['default_payment_due_day' => 5])->assertUnauthorized();
    }

    private function fixture(): array
    {
        $contract = Contract::factory()->create(['start_date' => null, 'monthly_rent' => null]);
        $house = $contract->room->boardingHouse;
        $owner = $house->landlord->user;
        $manager = User::factory()->create(['name' => 'Ngô Đức Bình']);
        $house->managers()->attach($manager);
        $roommate = Tenant::create(['landlord_id' => $house->landlord_id, 'full_name' => 'Trần Thị B']);
        $contract->handoverReports()->create(['type' => 'CHECK_IN', 'status' => 'CONFIRMED', 'items' => [['name' => 'Giường', 'quantity' => 1, 'condition' => 'GOOD', 'note' => null]], 'confirmed_at' => now()]);

        return [$contract, $owner, $manager, $roommate];
    }

    private function login(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }

    private function activate(Contract $contract, User $representative, array $overrides = []): array
    {
        return ['status' => 'ACTIVE', 'start_date' => '2026-10-01', 'end_date' => '2027-09-30',
            'monthly_rent' => 3800000, 'lessor_representative_user_id' => $representative->id, ...$overrides];
    }

    public function test_draft_can_be_created_without_date_or_rent_and_does_not_create_occupancy(): void
    {
        [$existing, $owner] = $this->fixture();
        $existing->room->update(['monthly_rent' => null]);
        $this->login($owner)->postJson("/api/rooms/{$existing->room_id}/contracts", [
            'tenant_id' => $existing->tenant_id, 'contract_code' => 'DRAFT-NULL', 'status' => 'DRAFT',
            'monthly_rent' => null, 'start_date' => null, 'signing_snapshot' => ['fake' => true],
        ])->assertCreated()->assertJsonStructure(['data' => ['id']]);

        $this->assertDatabaseHas('contracts', ['contract_code' => 'DRAFT-NULL', 'monthly_rent' => null, 'start_date' => null, 'signing_snapshot' => null]);
        $this->assertDatabaseCount('room_tenants', 0);
    }

    public function test_inactive_room_cannot_create_or_activate_a_contract(): void
    {
        [$contract, $owner] = $this->fixture();
        $contract->room->update(['status' => 'INACTIVE']);

        $this->login($owner)->postJson("/api/rooms/{$contract->room_id}/contracts", [
            'tenant_id' => $contract->tenant_id, 'contract_code' => 'INACTIVE-DRAFT', 'status' => 'DRAFT',
        ])->assertUnprocessable()->assertJsonValidationErrors('room_id');
        $this->putJson("/api/contracts/{$contract->id}", $this->activate($contract, $owner))
            ->assertUnprocessable()->assertJsonValidationErrors('room_id');

        $this->assertDatabaseMissing('contracts', ['contract_code' => 'INACTIVE-DRAFT']);
        $this->assertDatabaseCount('room_tenants', 0);
    }

    public function test_activation_requires_date_price_and_representative_and_rolls_back(): void
    {
        [$contract, $owner] = $this->fixture();
        $this->login($owner)->putJson("/api/contracts/{$contract->id}/status", ['status' => 'ACTIVE'])
            ->assertUnprocessable()->assertJsonValidationErrors(['start_date', 'monthly_rent', 'lessor_representative_user_id']);

        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'status' => 'DRAFT', 'signing_snapshot' => null]);
        $this->assertDatabaseCount('room_tenants', 0);
    }

    public function test_activation_snapshots_both_parties_and_price_and_never_changes_with_master_data(): void
    {
        [$contract, $owner, $manager] = $this->fixture();
        $contract->tenant->update(['full_name' => 'Nguyễn Văn A', 'phone' => '0900123456', 'identity_number' => '012345678901']);
        $this->login($owner)->putJson("/api/contracts/{$contract->id}", $this->activate($contract, $manager))->assertOk();

        $snapshot = $contract->fresh()->signing_snapshot;
        $this->assertSame('Ngô Đức Bình', $snapshot['lessor']['full_name']);
        $this->assertSame('MANAGER', $snapshot['lessor']['role']);
        $this->assertSame('0900123456', $snapshot['tenant']['phone']);
        $this->assertSame('2026-10-01', $snapshot['contract']['start_date']);
        $this->assertSame('3800000.00', $snapshot['contract']['monthly_rent']);
        $contract->tenant->update(['full_name' => 'Tên mới', 'phone' => '0999999999']);
        $manager->update(['name' => 'Quản lý mới']);
        $contract->room->update(['monthly_rent' => 4200000]);
        $contract->room->boardingHouse->managers()->detach($manager);
        $this->getJson("/api/contracts/{$contract->id}")->assertOk()
            ->assertJsonPath('data.signing_snapshot', $snapshot)
            ->assertJsonPath('data.monthly_rent', '3800000.00');
    }

    public function test_active_core_is_locked_but_internal_note_can_change_and_delete_is_refused(): void
    {
        [$contract, $owner] = $this->fixture();
        $this->login($owner)->putJson("/api/contracts/{$contract->id}", $this->activate($contract, $owner))->assertOk();
        $snapshot = $contract->fresh()->signing_snapshot;
        $this->putJson("/api/contracts/{$contract->id}", ['monthly_rent' => 0])->assertUnprocessable()->assertJsonValidationErrors('monthly_rent');
        $this->putJson("/api/contracts/{$contract->id}", ['service_ids' => []])->assertUnprocessable();
        $this->putJson("/api/contracts/{$contract->id}", ['note' => null, 'signing_snapshot' => ['fake' => true]])->assertOk();
        $this->deleteJson("/api/contracts/{$contract->id}")->assertUnprocessable();
        $this->assertSame($snapshot, $contract->fresh()->signing_snapshot);
        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'monthly_rent' => 3800000, 'note' => null]);
    }

    public function test_primary_and_roommate_share_room_tenant_source_and_move_out_preserves_history(): void
    {
        [$contract, $owner, , $roommate] = $this->fixture();
        $this->login($owner)->putJson("/api/contracts/{$contract->id}", $this->activate($contract, $owner, ['occupant_tenant_ids' => [$roommate->id]]))->assertOk();
        $this->assertDatabaseHas('room_tenants', ['contract_id' => $contract->id, 'tenant_id' => $contract->tenant_id, 'is_primary' => true, 'status' => 'ACTIVE']);
        $membership = RoomTenant::where('contract_id', $contract->id)->where('tenant_id', $roommate->id)->sole();
        $this->assertFalse($membership->is_primary);
        $this->deleteJson("/api/room-tenants/{$membership->id}")->assertUnprocessable();
        $this->putJson("/api/contracts/{$contract->id}/occupants/{$membership->id}/move-out", ['move_out_date' => '2026-10-15'])->assertOk();
        $this->assertDatabaseHas('room_tenants', ['id' => $membership->id, 'status' => 'MOVED_OUT', 'move_out_date' => '2026-10-15']);
        $this->assertDatabaseCount('room_tenants', 2);
        $this->getJson("/api/contracts/{$contract->id}")->assertJsonPath('data.active_occupants_count', 1);
    }

    public function test_existing_unlinked_active_primary_blocks_activation(): void
    {
        [$contract, $owner] = $this->fixture();
        $membership = RoomTenant::create(['room_id' => $contract->room_id, 'tenant_id' => $contract->tenant_id, 'move_in_date' => '2026-09-01', 'is_primary' => true, 'status' => 'ACTIVE']);
        $this->login($owner)->putJson("/api/contracts/{$contract->id}", $this->activate($contract, $owner))
            ->assertUnprocessable()->assertJsonValidationErrors('tenant_id');
        $this->assertDatabaseCount('room_tenants', 1);
        $this->assertDatabaseHas('room_tenants', ['id' => $membership->id, 'contract_id' => null, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'status' => 'DRAFT']);
    }

    public function test_terminated_contract_with_moved_out_primary_allows_new_contract_without_changing_history(): void
    {
        [$old, $owner] = $this->fixture();
        $old->tenant->update(['full_name' => 'Nguyễn Văn Cũ']);
        $this->login($owner)->putJson("/api/contracts/{$old->id}", $this->activate($old, $owner))->assertOk();
        $oldOccupancy = $old->occupants()->where('is_primary', true)->sole();
        $this->putJson("/api/contracts/{$old->id}/status", ['status' => 'TERMINATED', 'move_out_date' => '2026-10-23'])->assertOk();
        $new = Contract::factory()->create(['room_id' => $old->room_id, 'tenant_id' => $old->tenant_id, 'contract_code' => 'NEW-SAME-TENANT', 'start_date' => null, 'monthly_rent' => null]);
        $new->handoverReports()->create(['type' => 'CHECK_IN', 'status' => 'CONFIRMED', 'items' => [['name' => 'Giường', 'quantity' => 1, 'condition' => 'GOOD']], 'confirmed_at' => now()]);

        $this->putJson("/api/contracts/{$new->id}", $this->activate($new, $owner, ['start_date' => '2026-10-24']))->assertOk();
        $this->putJson("/api/contracts/{$new->id}/status", ['status' => 'ACTIVE'])->assertOk();

        $this->assertDatabaseHas('room_tenants', ['id' => $oldOccupancy->id, 'status' => 'MOVED_OUT', 'move_out_date' => '2026-10-23']);
        $this->assertDatabaseHas('room_tenants', ['contract_id' => $new->id, 'tenant_id' => $old->tenant_id, 'status' => 'ACTIVE', 'is_primary' => true]);
        $this->assertDatabaseCount('room_tenants', 2);
    }

    public function test_terminated_contract_without_move_out_keeps_primary_and_blocks_new_activation(): void
    {
        [$old, $owner] = $this->fixture();
        $old->tenant->update(['full_name' => 'Nguyễn Văn Đang Ở']);
        $this->login($owner)->putJson("/api/contracts/{$old->id}", $this->activate($old, $owner))->assertOk();
        $this->putJson("/api/contracts/{$old->id}/status", ['status' => 'TERMINATED'])->assertOk();
        $new = Contract::factory()->create(['room_id' => $old->room_id, 'contract_code' => 'NEW-OTHER-TENANT', 'start_date' => null, 'monthly_rent' => null]);
        $new->handoverReports()->create(['type' => 'CHECK_IN', 'status' => 'CONFIRMED', 'items' => [['name' => 'Giường', 'quantity' => 1, 'condition' => 'GOOD']], 'confirmed_at' => now()]);

        $this->putJson("/api/contracts/{$new->id}", $this->activate($new, $owner, ['start_date' => '2026-10-24']))
            ->assertUnprocessable()->assertJsonValidationErrors('tenant_id');

        $this->assertDatabaseHas('contracts', ['id' => $new->id, 'status' => 'DRAFT']);
        $this->assertDatabaseHas('room_tenants', ['contract_id' => $old->id, 'status' => 'ACTIVE', 'move_out_date' => null]);
        $this->assertDatabaseCount('room_tenants', 1);
    }

    public function test_duplicate_foreign_and_over_capacity_occupants_are_rejected_atomically(): void
    {
        [$contract, $owner, , $roommate] = $this->fixture();
        $foreign = Contract::factory()->create()->tenant;
        $this->login($owner)->putJson("/api/contracts/{$contract->id}", ['occupant_tenant_ids' => [$roommate->id, $roommate->id]])->assertUnprocessable();
        $this->putJson("/api/contracts/{$contract->id}", ['occupant_tenant_ids' => [$foreign->id]])->assertUnprocessable();
        $contract->room->update(['max_tenants' => 1]);
        $this->putJson("/api/contracts/{$contract->id}", $this->activate($contract, $owner, ['occupant_tenant_ids' => [$roommate->id]]))->assertUnprocessable()->assertJsonValidationErrors('occupant_tenant_ids');
        $this->assertDatabaseCount('room_tenants', 0);
        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'status' => 'DRAFT', 'monthly_rent' => null]);
    }

    public function test_service_and_tier_snapshots_are_frozen_and_meter_uses_latest_reading(): void
    {
        [$contract, $owner] = $this->fixture();
        $room = $contract->room;
        $service = Service::create(['boarding_house_id' => $room->boarding_house_id, 'name' => 'Điện', 'type' => 'ELECTRICITY', 'billing_method' => 'TIERED', 'base_price' => null, 'vat_rate' => 8, 'unit' => 'kWh', 'is_active' => true]);
        $service->priceTiers()->create(['from_quantity' => 0, 'to_quantity' => null, 'unit_price' => 4000, 'tier_order' => 1]);
        $room->services()->attach($service, ['is_active' => true]);
        $meter = UtilityMeter::create(['room_id' => $room->id, 'service_id' => $service->id, 'initial_reading' => 0, 'is_active' => true]);
        UtilityReading::create(['utility_meter_id' => $meter->id, 'reading_date' => '2026-09-01', 'reading_value' => 1200]);
        UtilityReading::create(['utility_meter_id' => $meter->id, 'reading_date' => '2026-10-01', 'reading_value' => 1800]);
        $this->login($owner)->getJson("/api/rooms/{$room->id}/contract-context")->assertOk()->assertJsonPath('data.utility_openings.0.opening_reading', '1800.00');
        $this->putJson("/api/contracts/{$contract->id}", $this->activate($contract, $owner, ['service_ids' => [$service->id], 'utility_openings' => [['utility_meter_id' => $meter->id, 'opening_reading' => 1800, 'reading_date' => '2026-10-01']]]))->assertOk();
        $snapshot = $contract->fresh()->signing_snapshot;
        $this->assertSame('4000.00', $snapshot['services'][0]['pricing_snapshot'][0]['unit_price']);
        $this->assertSame('1800.00', $snapshot['utility_openings'][0]['opening_reading']);
        $service->update(['base_price' => 9000, 'vat_rate' => 10]);
        $service->priceTiers()->update(['unit_price' => 8000]);
        $this->assertSame($snapshot, $contract->fresh()->signing_snapshot);
        $this->assertDatabaseCount('utility_readings', 2);
    }

    public function test_opening_falls_back_to_meter_initial_and_rejects_foreign_meter_or_service(): void
    {
        [$contract, $owner] = $this->fixture();
        $room = $contract->room;
        $service = Service::create(['boarding_house_id' => $room->boarding_house_id, 'name' => 'Nước', 'type' => 'WATER', 'billing_method' => 'PER_UNIT', 'base_price' => 20000, 'unit' => 'm³', 'is_active' => true]);
        $room->services()->attach($service, ['is_active' => true]);
        $meter = UtilityMeter::create(['room_id' => $room->id, 'service_id' => $service->id, 'initial_reading' => 123, 'is_active' => true]);
        $this->login($owner)->getJson("/api/rooms/{$room->id}/contract-context")->assertJsonPath('data.utility_openings.0.opening_reading', '123.00');
        $this->putJson("/api/contracts/{$contract->id}", ['service_ids' => [99999]])->assertUnprocessable();
        $this->putJson("/api/contracts/{$contract->id}", ['utility_openings' => [['utility_meter_id' => 99999, 'opening_reading' => 0, 'reading_date' => '2026-10-01']]])->assertUnprocessable();
        $this->assertDatabaseCount('contract_utility_openings', 0);
        $this->assertDatabaseHas('utility_meters', ['id' => $meter->id, 'initial_reading' => 123]);
    }

    public function test_manager_scope_context_and_writes_and_representative_grant_are_enforced(): void
    {
        [$contract, $owner, $manager] = $this->fixture();
        $foreign = Contract::factory()->create();
        $this->login($manager)->getJson("/api/rooms/{$contract->room_id}/contract-context")->assertOk();
        $this->getJson("/api/contracts/{$contract->id}")->assertOk();
        $this->getJson("/api/contracts/{$foreign->id}")->assertNotFound();
        $this->getJson("/api/rooms/{$foreign->room_id}/contracts")->assertNotFound();
        $this->putJson("/api/contracts/{$foreign->id}", ['note' => 'x'])->assertNotFound();
        $this->putJson("/api/contracts/{$contract->id}", $this->activate($contract, $manager))->assertOk();
        $this->putJson("/api/boarding-houses/{$contract->room->boarding_house_id}/contract-schedule", ['default_payment_due_day' => 5])->assertForbidden();
        $contract->room->boardingHouse->managers()->detach($manager);
        $this->getJson("/api/contracts/{$contract->id}")->assertNotFound();
        $this->login($owner)->getJson("/api/contracts/{$contract->id}")->assertOk();
    }

    public function test_unassigned_representative_cannot_be_selected(): void
    {
        [$contract, $owner] = $this->fixture();
        $outsider = User::factory()->create();
        $this->login($owner)->putJson("/api/contracts/{$contract->id}", $this->activate($contract, $outsider))->assertUnprocessable()->assertJsonValidationErrors('lessor_representative_user_id');
        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'status' => 'DRAFT']);
    }

    public function test_schedule_defaults_are_snapshotted_and_dates_clamp_to_month_end(): void
    {
        [$contract, $owner] = $this->fixture();
        $house = $contract->room->boardingHouse;
        $this->login($owner)->putJson("/api/boarding-houses/{$house->id}/contract-schedule", ['default_invoice_issue_day_from' => 25, 'default_invoice_issue_day_to' => 28, 'default_payment_due_day' => 31])->assertOk();
        $this->postJson("/api/rooms/{$contract->room_id}/contracts", ['tenant_id' => $contract->tenant_id, 'contract_code' => 'POLICY', ...$this->activate($contract, $owner), 'status' => 'DRAFT'])->assertCreated();
        $created = Contract::where('contract_code', 'POLICY')->sole();
        $created->handoverReports()->create(['type' => 'CHECK_IN', 'status' => 'CONFIRMED', 'items' => [['name' => 'Giường', 'quantity' => 1, 'condition' => 'GOOD', 'note' => null]], 'confirmed_at' => now()]);
        $this->putJson("/api/contracts/{$created->id}/status", $this->activate($created, $owner))->assertOk();
        $created->refresh();
        $house->update(['default_payment_due_day' => 5]);
        $this->assertSame(31, $created->fresh()->signing_snapshot['contract']['payment_due_day']);
        $this->assertDatabaseHas('contracts', ['id' => $created->id, 'invoice_issue_day_from' => null, 'invoice_issue_day_to' => null]);
        $this->assertArrayNotHasKey('invoice_issue_day_from', $created->signing_snapshot['contract']);
        $this->assertArrayNotHasKey('invoice_issue_day_to', $created->signing_snapshot['contract']);
        $this->assertSame('2026-02-28', app(ContractService::class)->scheduleDate('2026-02-01', 31));
        $this->assertSame('2028-02-29', app(ContractService::class)->scheduleDate('2028-02-01', 31));
        $this->assertNull(app(ContractService::class)->scheduleDate('2026-02-01', null));
        $this->putJson("/api/boarding-houses/{$house->id}/contract-schedule", ['default_payment_due_day' => 32])->assertUnprocessable();
    }

    public function test_ending_contract_keeps_snapshot_and_occupancy_history_and_cannot_reopen(): void
    {
        $this->travelTo('2027-10-01');
        [$contract, $owner] = $this->fixture();
        $this->login($owner)->putJson("/api/contracts/{$contract->id}", $this->activate($contract, $owner))->assertOk();
        $snapshot = $contract->fresh()->signing_snapshot;
        $this->putJson("/api/contracts/{$contract->id}/status", ['status' => 'EXPIRED', 'move_out_date' => '2027-09-30'])->assertOk();
        $this->assertDatabaseHas('room_tenants', ['contract_id' => $contract->id, 'status' => 'MOVED_OUT', 'move_out_date' => '2027-09-30']);
        $this->assertSame($snapshot, $contract->fresh()->signing_snapshot);
        $this->putJson("/api/contracts/{$contract->id}", ['note' => 'Changed'])->assertUnprocessable();
        $this->putJson("/api/contracts/{$contract->id}/status", ['status' => 'ACTIVE'])->assertUnprocessable();
    }

    public function test_early_expiry_is_rejected_and_termination_can_keep_occupancy_active(): void
    {
        $this->travelTo('2026-10-15');
        [$contract, $owner] = $this->fixture();
        $this->login($owner)->putJson("/api/contracts/{$contract->id}", $this->activate($contract, $owner))->assertOk();
        $this->putJson("/api/contracts/{$contract->id}/status", ['status' => 'EXPIRED', 'move_out_date' => '2026-10-15'])->assertUnprocessable();
        $this->putJson("/api/contracts/{$contract->id}/status", ['status' => 'TERMINATED'])->assertOk();
        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'status' => 'TERMINATED']);
        $this->assertDatabaseHas('room_tenants', ['contract_id' => $contract->id, 'status' => 'ACTIVE', 'move_out_date' => null]);
    }

    public function test_lists_omit_pii_and_legacy_contract_does_not_fabricate_snapshot(): void
    {
        [$contract, $owner] = $this->fixture();
        $contract->update(['status' => 'ACTIVE', 'start_date' => '2026-10-01', 'monthly_rent' => 100]);
        $contract->tenant->update(['identity_number' => '012345678901', 'permanent_address' => 'Private']);
        $this->login($owner)->getJson("/api/contracts/{$contract->id}")->assertOk()->assertJsonPath('data.is_legacy', true)->assertJsonPath('data.signing_snapshot', null);
        $this->getJson("/api/rooms/{$contract->room_id}/contracts")->assertOk()->assertJsonMissingPath('data.0.signing_snapshot')
            ->assertJsonMissingPath('data.0.tenant.identity_number')->assertJsonPath('data.0.start_date', '2026-10-01');
    }

    public function test_residence_suggestion_uses_only_active_occupants_from_this_contract(): void
    {
        [$contract, $owner, , $roommate] = $this->fixture();
        $this->login($owner)->putJson("/api/contracts/{$contract->id}", $this->activate($contract, $owner, ['occupant_tenant_ids' => [$roommate->id]]))->assertOk();
        $this->getJson("/api/residence/rooms/{$contract->room_id}/context?contract_id={$contract->id}")
            ->assertOk()->assertJsonPath('data.suggested_declaration.declarant_tenant_id', $contract->tenant_id)
            ->assertJsonPath('data.suggested_declaration.members.0.tenant_id', $roommate->id);
        $foreign = Contract::factory()->create();
        $this->getJson("/api/residence/rooms/{$contract->room_id}/context?contract_id={$foreign->id}")->assertNotFound();
    }

    public function test_draft_without_rent_cannot_crash_invoice_generation(): void
    {
        [$contract, $owner] = $this->fixture();
        $invoice = $contract->invoices()->create(['room_id' => $contract->room_id, 'invoice_code' => 'NULL-CONTRACT', 'billing_period' => '2026-10-01', 'subtotal' => 0, 'discount_amount' => 0, 'total_amount' => 0, 'paid_amount' => 0, 'status' => 'DRAFT']);
        $this->login($owner)->postJson("/api/invoices/{$invoice->id}/generate")->assertUnprocessable()->assertJsonValidationErrors('contract_id');
        $this->assertDatabaseCount('invoice_items', 0);
    }

    public function test_activation_revalidates_draft_meter_and_rolls_back_when_it_is_no_longer_active(): void
    {
        [$contract, $owner] = $this->fixture();
        $room = $contract->room;
        $service = Service::create(['boarding_house_id' => $room->boarding_house_id, 'name' => 'Điện', 'type' => 'ELECTRICITY', 'billing_method' => 'PER_UNIT', 'base_price' => 4000, 'unit' => 'kWh', 'is_active' => true]);
        $room->services()->attach($service, ['is_active' => true]);
        $meter = UtilityMeter::create(['room_id' => $room->id, 'service_id' => $service->id, 'initial_reading' => 0, 'is_active' => true]);
        $this->login($owner)->putJson("/api/contracts/{$contract->id}", ['utility_openings' => [['utility_meter_id' => $meter->id, 'opening_reading' => 100, 'reading_date' => '2026-10-01']]])->assertOk();
        $meter->update(['is_active' => false]);

        $this->putJson("/api/contracts/{$contract->id}", $this->activate($contract, $owner))->assertUnprocessable()->assertJsonValidationErrors('utility_openings.0.utility_meter_id');

        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'status' => 'DRAFT', 'signing_snapshot' => null]);
        $this->assertDatabaseHas('contract_utility_openings', ['contract_id' => $contract->id, 'opening_reading' => 100]);
        $this->assertDatabaseCount('room_tenants', 0);
    }

    public function test_invoice_list_does_not_expose_contract_signing_pii(): void
    {
        [$contract, $owner] = $this->fixture();
        $this->login($owner)->putJson("/api/contracts/{$contract->id}", $this->activate($contract, $owner))->assertOk();
        $contract->invoices()->create(['room_id' => $contract->room_id, 'invoice_code' => 'LIST-PII', 'billing_period' => '2026-10-01', 'subtotal' => 0, 'discount_amount' => 0, 'total_amount' => 0, 'paid_amount' => 0, 'status' => 'DRAFT']);

        $this->getJson("/api/rooms/{$contract->room_id}/invoices")->assertOk()
            ->assertJsonMissingPath('data.0.contract.signing_snapshot')
            ->assertJsonMissingPath('data.0.contract.tenant');
    }

    public function test_draft_room_change_is_explicit_scoped_and_refreshes_only_room_specific_drafts(): void
    {
        [$contract, $owner] = $this->fixture();
        $contract->update(['monthly_rent' => 3800000, 'payment_due_day' => 5]);
        $newRoom = $contract->room->boardingHouse->rooms()->create(['room_code' => '102', 'monthly_rent' => 4200000, 'status' => 'AVAILABLE']);
        $this->login($owner)->putJson("/api/contracts/{$contract->id}/room", ['room_id' => $newRoom->id])->assertOk();
        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'room_id' => $newRoom->id, 'monthly_rent' => 3800000, 'payment_due_day' => 5]);
        $foreign = Contract::factory()->create();
        $this->putJson("/api/contracts/{$contract->id}/room", ['room_id' => $foreign->room_id])->assertNotFound();
        $this->putJson("/api/contracts/{$contract->id}", $this->activate($contract, $owner))->assertOk();
        $this->putJson("/api/contracts/{$contract->id}/room", ['room_id' => $contract->room_id])->assertUnprocessable();
        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'room_id' => $newRoom->id, 'status' => 'ACTIVE']);
    }

    public function test_schedule_can_have_optional_days_but_never_an_inverted_range(): void
    {
        [$contract, $owner] = $this->fixture();
        $houseId = $contract->room->boarding_house_id;
        $this->login($owner)->putJson("/api/boarding-houses/{$houseId}/contract-schedule", ['default_invoice_issue_day_from' => null, 'default_invoice_issue_day_to' => 28, 'default_payment_due_day' => null])->assertOk();
        $this->assertDatabaseHas('boarding_houses', ['id' => $houseId, 'default_invoice_issue_day_from' => null, 'default_invoice_issue_day_to' => 28, 'default_payment_due_day' => null]);
        $this->putJson("/api/boarding-houses/{$houseId}/contract-schedule", ['default_invoice_issue_day_from' => 28, 'default_invoice_issue_day_to' => 25])->assertUnprocessable()->assertJsonValidationErrors('default_invoice_issue_day_to');
    }

    #[TestWith([null])]
    #[TestWith([1])]
    #[TestWith([31])]
    public function test_contract_monthly_payment_day_can_override_house_default_and_draft_can_clear_it(?int $day): void
    {
        [$contract, $owner] = $this->fixture();
        $contract->room->boardingHouse->update(['default_payment_due_day' => 5]);

        $this->login($owner)->postJson("/api/rooms/{$contract->room_id}/contracts", [
            'tenant_id' => $contract->tenant_id, 'contract_code' => 'CUSTOM-DAY', 'status' => 'DRAFT',
            'payment_due_day' => $day, 'invoice_issue_day_from' => 28, 'invoice_issue_day_to' => 25,
        ])->assertCreated();

        $created = Contract::where('contract_code', 'CUSTOM-DAY')->sole();
        $this->assertDatabaseHas('contracts', ['id' => $created->id, 'payment_due_day' => $day, 'invoice_issue_day_from' => null, 'invoice_issue_day_to' => null]);
        $this->putJson("/api/contracts/{$created->id}", ['payment_due_day' => null])->assertOk();
        $this->assertDatabaseHas('contracts', ['id' => $created->id, 'payment_due_day' => null]);
    }

    #[TestWith([0])]
    #[TestWith([32])]
    #[TestWith([1.5])]
    public function test_invalid_monthly_payment_day_returns_422_without_changing_contract(int|float $day): void
    {
        [$contract, $owner] = $this->fixture();

        $this->login($owner)->putJson("/api/contracts/{$contract->id}", ['payment_due_day' => $day])
            ->assertUnprocessable()->assertJsonValidationErrors('payment_due_day');

        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'payment_due_day' => null]);
    }

    public function test_legacy_issue_range_does_not_block_activation_or_change_old_columns(): void
    {
        [$contract, $owner] = $this->fixture();
        $contract->forceFill(['invoice_issue_day_from' => 28, 'invoice_issue_day_to' => 25])->save();

        $this->login($owner)->putJson("/api/contracts/{$contract->id}", $this->activate($contract, $owner, ['payment_due_day' => null]))->assertOk();

        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'status' => 'ACTIVE', 'payment_due_day' => null, 'invoice_issue_day_from' => 28, 'invoice_issue_day_to' => 25]);
        $snapshot = $contract->fresh()->signing_snapshot['contract'];
        $this->assertArrayNotHasKey('invoice_issue_day_from', $snapshot);
        $this->assertArrayNotHasKey('invoice_issue_day_to', $snapshot);
        $this->assertNull($snapshot['payment_due_day']);
    }

    #[TestWith(['2026-02-01', 31, null, '2026-02-28'])]
    #[TestWith(['2028-02-01', 31, null, '2028-02-29'])]
    #[TestWith(['2026-04-01', 31, null, '2026-04-30'])]
    #[TestWith(['2026-10-01', 5, null, '2026-10-05'])]
    #[TestWith(['2026-10-01', 5, '2026-11-10', '2026-11-10'])]
    #[TestWith(['2026-10-01', null, null, null])]
    public function test_invoice_creation_uses_contract_due_day_with_month_end_clamp_and_preserves_explicit_date(string $period, ?int $day, ?string $explicitDate, ?string $expected): void
    {
        [$contract, $owner] = $this->fixture();
        $contract->update(['payment_due_day' => $day]);

        $this->login($owner)->postJson("/api/rooms/{$contract->room_id}/invoices", [
            'contract_id' => $contract->id, 'invoice_code' => 'DUE-DAY', 'billing_period' => $period,
            'status' => 'DRAFT', 'due_date' => $explicitDate, 'issued_at' => '2026-01-25',
        ])->assertCreated();

        $this->assertDatabaseHas('invoices', ['invoice_code' => 'DUE-DAY', 'contract_id' => $contract->id, 'billing_period' => $period, 'due_date' => $expected, 'issued_at' => '2026-01-25']);
    }
}
