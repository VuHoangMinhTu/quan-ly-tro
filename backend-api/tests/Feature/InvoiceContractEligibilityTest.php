<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class InvoiceContractEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private function contract(array $attributes = [], array $occupancy = []): Contract
    {
        $this->travelTo(now()->setDate(2026, 11, 15)->startOfDay());
        $contract = Contract::factory()->create(['status' => 'ACTIVE', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31', ...$attributes]);
        $contract->occupants()->create(['room_id' => $contract->room_id, 'tenant_id' => $contract->tenant_id, 'is_primary' => true,
            'move_in_date' => '2026-09-01', 'status' => 'ACTIVE', ...$occupancy]);
        $this->actingAs($contract->room->boardingHouse->landlord->user, 'sanctum');

        return $contract;
    }

    private function payload(Contract $contract, string $period = '2026-11-01'): array
    {
        return ['contract_id' => $contract->id, 'billing_period' => $period, 'status' => 'DRAFT'];
    }

    public function test_active_contract_is_listed_with_room_status_and_dates_and_can_be_created(): void
    {
        $contract = $this->contract();
        $this->getJson("/api/rooms/{$contract->room_id}/invoice-contracts?billing_period=2026-11-01")->assertOk()
            ->assertJsonCount(1, 'data.contracts')->assertJsonPath('data.contracts.0.id', $contract->id)
            ->assertJsonPath('data.contracts.0.status', 'ACTIVE')->assertJsonPath('data.contracts.0.room_code', '101')
            ->assertJsonPath('data.contracts.0.start_date', '2026-09-01')->assertJsonCount(0, 'data.warnings')
            ->assertJsonMissingPath('data.contracts.0.signing_snapshot');

        $this->postJson("/api/rooms/{$contract->room_id}/invoices", $this->payload($contract))->assertCreated();
        $this->assertDatabaseHas('invoices', ['contract_id' => $contract->id, 'billing_period' => '2026-11-01', 'total_amount' => 0]);
    }

    #[TestWith(['TERMINATED'])]
    #[TestWith(['EXPIRED'])]
    public function test_ended_contract_is_not_available_for_regular_creation_but_existing_history_remains_visible(string $status): void
    {
        $contract = $this->contract(['status' => $status, 'end_date' => '2026-10-20'], ['status' => 'MOVED_OUT', 'move_out_date' => '2026-10-20']);
        $this->getJson("/api/rooms/{$contract->room_id}/invoice-contracts?billing_period=2026-09-01")->assertOk()->assertJsonCount(0, 'data.contracts');
        $this->getJson("/api/rooms/{$contract->room_id}/invoice-contracts?billing_period=2026-11-01")->assertOk()->assertJsonCount(0, 'data.contracts');
        $this->postJson("/api/rooms/{$contract->room_id}/invoices", $this->payload($contract))->assertUnprocessable()
            ->assertJsonValidationErrors('contract_id');
        $this->assertDatabaseCount('invoices', 0);
        $this->postJson("/api/rooms/{$contract->room_id}/invoices", $this->payload($contract, '2026-09-01'))->assertUnprocessable();
        $oldInvoice = $contract->invoices()->create(['room_id' => $contract->room_id, 'billing_period' => '2026-09-01', 'status' => 'UNPAID']);
        $this->getJson("/api/invoices/{$oldInvoice->id}")->assertOk()->assertJsonPath('data.contract_id', $contract->id);
        $this->getJson("/api/rooms/{$contract->room_id}/invoices")->assertOk()->assertJsonPath('data.items.0.id', $oldInvoice->id);
        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'status' => $status]);
        $this->assertDatabaseHas('room_tenants', ['contract_id' => $contract->id, 'status' => 'MOVED_OUT', 'move_out_date' => '2026-10-20']);
    }

    public function test_active_contract_with_moved_out_primary_is_reported_and_cannot_be_used_for_current_period(): void
    {
        $contract = $this->contract([], ['status' => 'MOVED_OUT', 'move_out_date' => '2026-10-20']);
        $this->getJson("/api/rooms/{$contract->room_id}/invoice-contracts?billing_period=2026-11-01")->assertOk()
            ->assertJsonCount(0, 'data.contracts')->assertJsonPath('data.warnings.0.contract_id', $contract->id);
        $this->postJson("/api/rooms/{$contract->room_id}/invoices", $this->payload($contract))->assertUnprocessable()->assertJsonValidationErrors('contract_id');
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'status' => 'ACTIVE']);
        $this->getJson("/api/rooms/{$contract->room_id}/invoice-contracts?billing_period=2026-09-01")->assertOk()
            ->assertJsonCount(0, 'data.contracts')->assertJsonPath('data.warnings.0.contract_id', $contract->id);
    }

    public function test_room_transfer_keeps_old_contract_history_but_only_new_room_contract_is_available_for_new_period(): void
    {
        $old = $this->contract(['status' => 'TERMINATED', 'end_date' => '2026-10-20'], ['status' => 'MOVED_OUT', 'move_out_date' => '2026-10-20']);
        $room = Room::create(['boarding_house_id' => $old->room->boarding_house_id, 'room_code' => '102', 'status' => 'RENTED']);
        $new = $this->contract(['room_id' => $room->id, 'tenant_id' => $old->tenant_id, 'start_date' => '2026-10-21'], ['move_in_date' => '2026-10-21']);

        $this->getJson("/api/rooms/{$old->room_id}/invoice-contracts?billing_period=2026-11-01")->assertOk()->assertJsonCount(0, 'data.contracts');
        $this->getJson("/api/rooms/{$room->id}/invoice-contracts?billing_period=2026-11-01")->assertOk()->assertJsonPath('data.contracts.0.id', $new->id)->assertJsonCount(0, 'data.warnings');
        $this->postJson("/api/rooms/{$room->id}/invoices", $this->payload($old))->assertUnprocessable();
        $this->postJson("/api/rooms/{$room->id}/invoices", $this->payload($new))->assertCreated();
    }

    public function test_unlinked_or_wrong_room_occupancy_does_not_make_contract_eligible(): void
    {
        $contract = $this->contract();
        $contract->occupants()->update(['contract_id' => null]);
        $this->getJson("/api/rooms/{$contract->room_id}/invoice-contracts?billing_period=2026-11-01")->assertOk()->assertJsonCount(0, 'data.contracts');
        $this->postJson("/api/rooms/{$contract->room_id}/invoices", $this->payload($contract))->assertUnprocessable();
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_duplicate_room_period_is_rejected_and_cancelled_invoice_does_not_block_replacement(): void
    {
        $contract = $this->contract();
        $url = "/api/rooms/{$contract->room_id}/invoices";
        $this->postJson($url, $this->payload($contract))->assertCreated();
        $this->postJson($url, $this->payload($contract))->assertUnprocessable()->assertJsonValidationErrors('billing_period');
        $this->assertDatabaseCount('invoices', 1);
        $contract->invoices()->firstOrFail()->update(['status' => 'CANCELLED']);
        $this->postJson($url, $this->payload($contract))->assertCreated();
        $this->assertDatabaseCount('invoices', 2);
    }

    public function test_foreign_owner_and_invalid_period_are_rejected(): void
    {
        $contract = $this->contract();
        $this->getJson("/api/rooms/{$contract->room_id}/invoice-contracts?billing_period=invalid")->assertUnprocessable()->assertJsonValidationErrors('billing_period');
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->getJson("/api/rooms/{$contract->room_id}/invoice-contracts?billing_period=2026-11-01")->assertNotFound();
        $this->postJson("/api/rooms/{$contract->room_id}/invoices", $this->payload($contract))->assertNotFound();
        $this->assertDatabaseCount('invoices', 0);
    }

    #[TestWith([['status' => 'DRAFT']])]
    #[TestWith([['start_date' => '2026-12-01']])]
    public function test_draft_and_not_started_contracts_are_not_eligible(array $attributes): void
    {
        $contract = $this->contract($attributes);
        $this->getJson("/api/rooms/{$contract->room_id}/invoice-contracts?billing_period=2026-11-01")->assertOk()->assertJsonCount(0, 'data.contracts');
        $this->postJson("/api/rooms/{$contract->room_id}/invoices", $this->payload($contract))->assertUnprocessable()->assertJsonValidationErrors('contract_id');
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_historical_occupancy_must_overlap_the_contract_dates_not_merely_the_same_month(): void
    {
        $contract = $this->contract(['start_date' => '2026-10-23'], ['status' => 'MOVED_OUT', 'move_out_date' => '2026-10-20']);
        $this->getJson("/api/rooms/{$contract->room_id}/invoice-contracts?billing_period=2026-10-01")->assertOk()->assertJsonCount(0, 'data.contracts');
        $this->postJson("/api/rooms/{$contract->room_id}/invoices", $this->payload($contract, '2026-10-01'))->assertUnprocessable();
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_guest_cannot_list_invoice_contracts(): void
    {
        $contract = Contract::factory()->create();
        $this->getJson("/api/rooms/{$contract->room_id}/invoice-contracts?billing_period=2026-11-01")->assertUnauthorized();
    }

    public function test_empty_room_cannot_create_regular_invoice_without_contract(): void
    {
        $contract = $this->contract();
        $contract->occupants()->update(['status' => 'MOVED_OUT', 'move_out_date' => '2026-10-20']);
        $this->postJson("/api/rooms/{$contract->room_id}/invoices", ['billing_period' => '2026-11-01', 'status' => 'DRAFT'])->assertUnprocessable()->assertJsonValidationErrors('contract_id');
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_same_tenant_can_have_active_contracts_in_different_rooms(): void
    {
        $first = $this->contract();
        $room = Room::create(['boarding_house_id' => $first->room->boarding_house_id, 'room_code' => '102', 'status' => 'RENTED']);
        $second = $this->contract(['room_id' => $room->id, 'tenant_id' => $first->tenant_id]);
        foreach ([$first, $second] as $contract) {
            $this->getJson("/api/rooms/{$contract->room_id}/invoice-contracts?billing_period=2026-11-01")->assertOk()->assertJsonPath('data.contracts.0.id', $contract->id);
            $this->postJson("/api/rooms/{$contract->room_id}/invoices", $this->payload($contract))->assertCreated();
        }
        $this->assertDatabaseCount('invoices', 2);
    }

    public function test_dormitory_lists_only_occupied_beds_and_bills_each_contract_rent_once_per_period(): void
    {
        $first = $this->contract(['monthly_rent' => 1500000]);
        $room = $first->room;
        $room->update(['room_type' => 'DORMITORY', 'monthly_rent' => 9000000, 'max_tenants' => 3]);
        $bed1 = $room->beds()->create(['bed_code' => 'G01', 'status' => 'OCCUPIED', 'monthly_rent' => 1500000]);
        $bed2 = $room->beds()->create(['bed_code' => 'G02', 'status' => 'OCCUPIED', 'monthly_rent' => 1800000]);
        $bed3 = $room->beds()->create(['bed_code' => 'G03', 'status' => 'AVAILABLE', 'monthly_rent' => 2000000]);
        $first->update(['contract_type' => 'DORM_BED', 'bed_id' => $bed1->id]);
        $first->occupants()->update(['bed_id' => $bed1->id]);
        $second = $this->contract(['room_id' => $room->id, 'contract_type' => 'DORM_BED', 'bed_id' => $bed2->id, 'monthly_rent' => 1800000], ['bed_id' => $bed2->id]);
        $empty = Contract::factory()->create(['room_id' => $room->id, 'contract_type' => 'DORM_BED', 'bed_id' => $bed3->id, 'status' => 'ACTIVE']);
        $this->getJson("/api/rooms/{$room->id}/invoice-contracts?billing_period=2026-11-01")->assertOk()->assertJsonCount(2, 'data.contracts');

        foreach ([$first, $second] as $contract) {
            $this->postJson("/api/rooms/{$room->id}/invoices", $this->payload($contract))->assertCreated();
            $invoice = $contract->invoices()->firstOrFail();
            $this->postJson("/api/invoices/{$invoice->id}/generate")->assertOk();
            $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'RENT', 'amount' => $contract->monthly_rent]);
            $this->assertDatabaseMissing('invoice_items', ['invoice_id' => $invoice->id, 'amount' => 9000000]);
            $this->postJson("/api/rooms/{$room->id}/invoices", $this->payload($contract))->assertUnprocessable()->assertJsonValidationErrors('billing_period');
        }
        $this->postJson("/api/rooms/{$room->id}/invoices", $this->payload($empty))->assertUnprocessable()->assertJsonValidationErrors('contract_id');
        $this->assertDatabaseCount('invoices', 2);
        $first->occupants()->create(['room_id' => $room->id, 'tenant_id' => $second->tenant_id, 'bed_id' => $bed1->id, 'is_primary' => true, 'status' => 'ACTIVE', 'move_in_date' => '2026-09-01']);
        $this->getJson("/api/rooms/{$room->id}/invoice-contracts?billing_period=2026-11-01")->assertOk()->assertJsonCount(1, 'data.contracts')->assertJsonPath('data.contracts.0.id', $second->id);
        $this->postJson("/api/rooms/{$room->id}/invoices", $this->payload($first))->assertUnprocessable()->assertJsonValidationErrors('contract_id');
    }

    public function test_changing_invoice_period_cannot_bypass_contract_eligibility(): void
    {
        $contract = $this->contract(['status' => 'TERMINATED', 'end_date' => '2026-10-20'], ['status' => 'MOVED_OUT', 'move_out_date' => '2026-10-20']);
        $invoice = $contract->invoices()->create(['room_id' => $contract->room_id, 'billing_period' => '2026-09-01', 'status' => 'DRAFT']);
        $before = $invoice->fresh()->getAttributes();

        $this->putJson("/api/invoices/{$invoice->id}", $this->payload($contract))->assertUnprocessable()->assertJsonValidationErrors('contract_id');

        $this->assertSame($before, $invoice->fresh()->getAttributes());
    }

    public function test_draft_generator_rechecks_occupancy_without_deleting_preexisting_items(): void
    {
        $contract = $this->contract([], ['status' => 'MOVED_OUT', 'move_out_date' => '2026-10-20']);
        $invoice = $contract->invoices()->create(['room_id' => $contract->room_id, 'billing_period' => '2026-11-01', 'status' => 'DRAFT']);
        $item = $invoice->items()->create(['type' => 'RENT', 'description' => 'Khoản thu đã ghi nhận', 'quantity' => 1, 'unit_price' => 3000000, 'amount' => 3000000, 'source' => 'AUTO']);
        $invoice->refresh();
        $invoice->recalculateTotals();
        $before = $invoice->fresh()->getAttributes();

        $this->postJson("/api/invoices/{$invoice->id}/generate")->assertUnprocessable()->assertJsonValidationErrors('contract_id');

        $this->assertModelExists($item);
        $this->assertSame($before, $invoice->fresh()->getAttributes());
        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'status' => 'ACTIVE']);
    }
}
