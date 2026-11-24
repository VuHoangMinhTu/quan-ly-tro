<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvoiceDraftHardDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function setupContract(): Contract
    {
        $contract = Contract::factory()->create([
            'status' => 'ACTIVE', 'start_date' => '2026-10-01', 'end_date' => null,
        ]);
        $contract->forceFill(['service_terms_version' => 1])->save();
        $contract->handoverReports()->create([
            'type' => 'CHECK_IN', 'status' => 'CONFIRMED', 'items' => [], 'confirmed_at' => now(),
        ]);
        $owner = $contract->room->boardingHouse->landlord->user;
        $this->actingAs($owner, 'sanctum');
        config(['billing.v2_pilot_user_ids' => [$owner->id]]);

        return $contract;
    }

    private function createMonthly(Contract $contract): int
    {
        return $this->postJson('/api/billing-v2/rooms/'.$contract->room_id.'/monthly-invoices', [
            'billing_period' => '2026-10-01',
            'usage_start_at' => '2026-10-01 00:00:00',
            'usage_end_at' => '2026-11-01 00:00:00',
        ])->assertOk()->json('data.0.invoices.0.id');
    }

    public function test_monthly_v2_draft_is_physically_deleted_and_same_period_can_be_created_again(): void
    {
        $contract = $this->setupContract();
        $id = $this->createMonthly($contract);
        $this->assertDatabaseCount('monthly_invoice_compositions', 1);
        $this->assertDatabaseCount('invoice_obligations', 1);

        $this->deleteJson('/api/invoices/'.$id)->assertOk();
        $this->assertNull(Invoice::withTrashed()->find($id));
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseCount('invoice_obligations', 0);
        $this->assertDatabaseCount('monthly_invoice_compositions', 0);
        $this->getJson('/api/rooms/'.$contract->room_id.'/invoices')->assertOk()->assertJsonCount(0, 'data.items');
        $new = $this->createMonthly($contract);
        $this->assertNotEquals($id, $new);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('monthly_invoice_compositions', 1);
        $this->assertDatabaseCount('invoice_obligations', 1);
    }

    public function test_utility_calculation_is_removed_but_confirmed_checkpoints_are_kept_for_rebilling(): void
    {
        $contract = $this->setupContract();
        $service = $contract->room->boardingHouse->services()->create([
            'name' => 'Dien', 'type' => 'ELECTRICITY', 'billing_method' => 'PER_UNIT',
            'base_price' => 5000, 'unit' => 'kWh', 'is_active' => true,
        ]);
        $contract->room->services()->attach($service, ['is_active' => true]);
        $contract->agreedServices()->create([
            'service_id' => $service->id, 'service_name' => $service->name,
            'service_type' => 'ELECTRICITY', 'billing_method' => 'PER_UNIT',
            'base_price' => 5000, 'unit' => 'kWh', 'is_active' => true,
            'effective_from' => '2026-10-01', 'pricing_snapshot' => [],
        ]);
        $meter = $contract->room->utilityMeters()->create([
            'service_id' => $service->id, 'is_active' => true,
        ]);
        foreach ([
            ['PERIOD_BOUNDARY', null, '2026-10-01 00:00:00', 100],
            ['CHECK_IN', $contract->id, '2026-10-01 00:00:00', 100],
            ['PERIOD_BOUNDARY', null, '2026-11-01 00:00:00', 115],
        ] as [$kind, $contractId, $time, $value]) {
            $this->postJson('/api/billing-v2/meters/'.$meter->id.'/checkpoints', [
                'kind' => $kind, 'contract_id' => $contractId,
                'observed_at' => $time, 'reading_value' => $value,
            ])->assertOk();
        }
        $id = $this->createMonthly($contract);
        $this->assertDatabaseCount('utility_billing_periods', 1);
        $this->assertDatabaseCount('utility_charge_allocations', 1);
        $checkpoints = DB::table('utility_checkpoints')->count();

        $this->deleteJson('/api/invoices/'.$id)->assertOk();
        $this->assertDatabaseCount('utility_billing_periods', 0);
        $this->assertDatabaseCount('utility_charge_allocations', 0);
        $this->assertSame($checkpoints, DB::table('utility_checkpoints')->count());
        $new = $this->createMonthly($contract);
        $this->assertNotEquals($id, $new);
        $this->assertDatabaseCount('utility_billing_periods', 1);
        $this->assertDatabaseCount('utility_charge_allocations', 1);
    }

    public function test_issued_and_payment_related_drafts_are_not_deleted(): void
    {
        $contract = $this->setupContract();
        $id = $this->createMonthly($contract);
        $invoice = Invoice::findOrFail($id);
        $invoice->payosPaymentRequests()->create(['status' => 'PENDING', 'amount' => 1000]);
        $this->deleteJson('/api/invoices/'.$id)->assertUnprocessable()->assertJsonValidationErrors('payments');
        $this->assertNotNull(Invoice::find($id));
        $invoice->payosPaymentRequests()->delete();
        $invoice->update(['status' => 'UNPAID', 'issued_at' => '2026-11-01']);
        $this->deleteJson('/api/invoices/'.$id)->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseCount('invoice_obligations', 1);
    }

    public function test_dormitory_can_delete_one_bed_draft_without_touching_another_bed(): void
    {
        $a = $this->setupContract();
        $a->update(['start_date' => '2026-11-05']);
        $room = $a->room;
        $room->update(['room_type' => 'DORMITORY']);
        $bedA = $room->beds()->create(['bed_code' => 'A01', 'monthly_rent' => 1500000]);
        $bedB = $room->beds()->create(['bed_code' => 'B01', 'monthly_rent' => 1700000]);
        $a->forceFill(['contract_type' => 'DORM_BED', 'bed_id' => $bedA->id])->save();
        $b = Contract::factory()->create([
            'room_id' => $room->id,
            'tenant_id' => Tenant::create([
                'landlord_id' => $room->boardingHouse->landlord_id, 'full_name' => 'Bed B',
            ])->id,
            'contract_type' => 'DORM_BED', 'bed_id' => $bedB->id,
            'status' => 'ACTIVE', 'start_date' => '2026-11-15',
            'end_date' => null, 'monthly_rent' => 1700000,
            'service_terms_version' => 1,
        ]);
        $b->handoverReports()->create([
            'type' => 'CHECK_IN', 'status' => 'CONFIRMED', 'items' => [], 'confirmed_at' => now(),
        ]);
        $path = fn ($c) => '/api/billing-v2/contracts/'.$c->id.'/bed-invoices';
        $idA = $this->postJson($path($a), ['billing_period' => '2026-11-01'])
            ->assertOk()->json('data.invoices.0.id');
        $idB = $this->postJson($path($b), ['billing_period' => '2026-11-01'])
            ->assertOk()->json('data.invoices.0.id');
        $this->deleteJson('/api/invoices/'.$idA)->assertOk();
        $this->assertNull(Invoice::withTrashed()->find($idA));
        $this->assertNotNull(Invoice::find($idB));
        $newA = $this->postJson($path($a), ['billing_period' => '2026-11-01'])
            ->assertOk()->json('data.invoices.0.id');
        $this->assertNotEquals($idA, $newA);
        $this->assertDatabaseCount('invoices', 2);
    }

    public function test_legacy_draft_is_physically_deleted_but_a_payment_record_blocks_deletion(): void
    {
        $contract = $this->setupContract();
        $invoice = Invoice::create([
            'room_id' => $contract->room_id, 'contract_id' => $contract->id,
            'invoice_code' => 'INV-TEST-DELETE-1', 'billing_period' => '2026-09-01',
            'status' => 'DRAFT',
        ]);
        $this->deleteJson('/api/invoices/'.$invoice->id)->assertOk();
        $this->assertNull(Invoice::withTrashed()->find($invoice->id));

        $another = Invoice::create([
            'room_id' => $contract->room_id, 'contract_id' => $contract->id,
            'invoice_code' => 'INV-TEST-DELETE-2', 'billing_period' => '2026-09-01',
            'status' => 'DRAFT',
        ]);
        $another->payments()->create([
            'amount' => 1000, 'payment_method' => 'CASH',
            'paid_at' => '2026-09-25 10:00:00',
        ]);
        $this->deleteJson('/api/invoices/'.$another->id)->assertUnprocessable()->assertJsonValidationErrors('payments');
        $this->assertNotNull(Invoice::find($another->id));
    }

    public function test_private_room_shared_period_refuses_deleting_only_one_invoice(): void
    {
        $contract = $this->setupContract();
        $id = $this->createMonthly($contract);
        $other = Invoice::create([
            'room_id' => $contract->room_id, 'contract_id' => $contract->id,
            'invoice_code' => 'INV-OTHER-SAME-MONTH',
            'billing_period' => '2026-10-01', 'status' => 'DRAFT',
        ]);
        $this->deleteJson('/api/invoices/'.$id)->assertUnprocessable()->assertJsonValidationErrors('invoice_review');
        $this->assertNotNull(Invoice::find($id));
        $this->assertNotNull(Invoice::find($other->id));
        $this->assertDatabaseCount('monthly_invoice_compositions', 1);
    }

}
