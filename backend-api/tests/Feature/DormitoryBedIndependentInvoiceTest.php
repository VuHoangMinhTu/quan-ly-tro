<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DormitoryBedIndependentInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function setupBeds(): array
    {
        $a = Contract::factory()->create(['status' => 'ACTIVE', 'start_date' => '2026-11-05',
            'end_date' => null, 'monthly_rent' => 1500000]);
        $room = $a->room;
        $room->update(['room_type' => 'DORMITORY']);
        $bedA = $room->beds()->create(['bed_code' => 'A01', 'monthly_rent' => 1500000]);
        $bedB = $room->beds()->create(['bed_code' => 'B01', 'monthly_rent' => 1700000]);
        $a->forceFill(['contract_type' => 'DORM_BED', 'bed_id' => $bedA->id,
            'service_terms_version' => 1])->save();
        $b = Contract::factory()->create(['room_id' => $room->id,
            'tenant_id' => Tenant::create(['landlord_id' => $room->boardingHouse->landlord_id,
                'full_name' => 'Nguoi o giuong B'])->id,
            'contract_type' => 'DORM_BED', 'bed_id' => $bedB->id,
            'status' => 'ACTIVE', 'start_date' => '2026-11-15', 'end_date' => null,
            'monthly_rent' => 1700000, 'service_terms_version' => 1]);
        foreach ([$a, $b] as $contract) {
            $contract->handoverReports()->create(['type' => 'CHECK_IN', 'status' => 'CONFIRMED',
                'items' => [], 'confirmed_at' => now()]);
        }
        $owner = $room->boardingHouse->landlord->user;
        $this->actingAs($owner, 'sanctum');
        config(['billing.v2_pilot_user_ids' => [$owner->id]]);
        return [$room, $a, $b];
    }

    public function test_two_beds_with_different_anniversaries_have_independent_invoices(): void
    {
        [$room, $a, $b] = $this->setupBeds();
        $url = '/api/billing-v2/rooms/'.$room->id.'/monthly-invoices';
        $response = $this->postJson($url, [
            'billing_period' => '2026-11-01', 'usage_start_at' => '2026-11-05 00:00:00',
            'usage_end_at' => '2026-12-05 00:00:00',
        ])->assertOk()->assertJsonPath('data.0.status', 'CREATED');
        $this->assertCount(2, $response->json('data.0.invoices'));
        $this->assertDatabaseHas('invoices', ['contract_id' => $a->id, 'total_amount' => 1500000, 'status' => 'DRAFT']);
        $this->assertDatabaseHas('invoices', ['contract_id' => $b->id, 'total_amount' => 1700000, 'status' => 'DRAFT']);
        $records = \DB::table('monthly_invoice_compositions')->get()->keyBy('contract_id');
        $windowA = json_decode($records[$a->id]->snapshot, true)['usage_window'];
        $windowB = json_decode($records[$b->id]->snapshot, true)['usage_window'];
        $this->assertSame('2026-11-05 00:00:00', $windowA['start']);
        $this->assertSame('2026-12-05 00:00:00', $windowA['end']);
        $this->assertSame('2026-11-15 00:00:00', $windowB['start']);
        $this->assertSame('2026-12-15 00:00:00', $windowB['end']);
        $this->postJson($url, [
            'billing_period' => '2026-11-01', 'usage_start_at' => '2026-11-05 00:00:00',
            'usage_end_at' => '2026-12-05 00:00:00',
        ])->assertOk()->assertJsonPath('data.0.status', 'EXISTING');
        $this->assertDatabaseCount('invoices', 2);
    }

    public function test_issued_invoice_of_bed_a_does_not_block_bed_b(): void
    {
        [$room, $a, $b] = $this->setupBeds();
        $url = fn ($contract) => '/api/billing-v2/contracts/'.$contract->id.'/bed-invoices';
        $id = $this->postJson($url($a), ['billing_period' => '2026-11-01'])->assertOk()
            ->assertJsonPath('data.status', 'CREATED')->json('data.invoices.0.id');
        $this->postJson('/api/billing-v2/invoices/'.$id.'/issue')->assertOk();
        $other = $this->postJson($url($b), ['billing_period' => '2026-11-01'])->assertOk()
            ->assertJsonPath('data.status', 'CREATED')->json('data.invoices.0.id');
        $this->assertNotEquals($id, $other);
        $this->assertDatabaseHas('invoices', ['id' => $id, 'status' => 'UNPAID']);
        $this->assertDatabaseHas('invoices', ['id' => $other, 'status' => 'DRAFT']);
        $this->assertDatabaseCount('invoices', 2);
    }

    public function test_one_partial_bed_needs_approval_but_other_bed_can_bill(): void
    {
        [$room, $a, $b] = $this->setupBeds();
        $a->update(['status' => 'EXPIRED', 'end_date' => '2026-11-20']);
        $url = fn ($contract) => '/api/billing-v2/contracts/'.$contract->id.'/bed-invoices';
        $this->postJson($url($a), ['billing_period' => '2026-11-01'])->assertOk()
            ->assertJsonPath('data.status', 'BLOCKED');
        $this->assertDatabaseCount('invoices', 0);
        $this->postJson($url($b), ['billing_period' => '2026-11-01'])->assertOk()
            ->assertJsonPath('data.status', 'CREATED');
        $this->postJson($url($a), ['billing_period' => '2026-11-01', 'confirmation' => [
            'rent_amount' => 700000, 'reason' => 'Thoa thuan ket thuc som hop dong',
        ]])->assertOk()->assertJsonPath('data.status', 'CREATED');
        $this->assertDatabaseHas('invoices', ['contract_id' => $a->id, 'total_amount' => 700000]);
        $this->assertDatabaseHas('invoices', ['contract_id' => $b->id, 'total_amount' => 1700000]);
    }

    public function test_bed_can_change_tenants_without_inheriting_old_tenants_rent_cycle(): void
    {
        [$room, $a, $b] = $this->setupBeds();
        $a->update(['status' => 'EXPIRED', 'end_date' => '2026-11-14']);
        $b->update(['bed_id' => $a->bed_id, 'start_date' => '2026-11-15']);
        $aResult = $this->postJson('/api/billing-v2/contracts/'.$a->id.'/bed-invoices', [
            'billing_period' => '2026-11-01', 'confirmation' => [
                'rent_amount' => 500000, 'reason' => 'Thoa thuan tra giuong truoc han',
            ],
        ])->assertOk()->assertJsonPath('data.status', 'CREATED');
        $bResult = $this->postJson('/api/billing-v2/contracts/'.$b->id.'/bed-invoices', [
            'billing_period' => '2026-11-01',
        ])->assertOk()->assertJsonPath('data.status', 'CREATED');
        $this->assertNotEquals($aResult->json('data.invoices.0.id'), $bResult->json('data.invoices.0.id'));
        $this->assertDatabaseHas('invoices', ['contract_id' => $a->id, 'total_amount' => 500000]);
        $this->assertDatabaseHas('invoices', ['contract_id' => $b->id, 'total_amount' => 1700000]);
    }

    public function test_one_bed_draft_preview_does_not_rewrite_issued_other_bed(): void
    {
        [$room, $a, $b] = $this->setupBeds();
        $url = fn ($contract) => '/api/billing-v2/contracts/'.$contract->id.'/bed-invoices';
        $idA = $this->postJson($url($a), ['billing_period' => '2026-11-01'])
            ->assertOk()->json('data.invoices.0.id');
        $idB = $this->postJson($url($b), ['billing_period' => '2026-11-01'])
            ->assertOk()->json('data.invoices.0.id');
        $this->postJson('/api/billing-v2/invoices/'.$idA.'/issue')->assertOk();
        $prefix = '/api/billing-v2/rooms/'.$room->id.'/monthly-recalculations';
        $preview = $this->postJson($prefix.'/preview', [
            'billing_period' => '2026-11-01', 'contract_id' => $b->id,
            'usage_start_at' => '2026-11-15 00:00:00', 'usage_end_at' => '2026-12-15 00:00:00',
        ])->assertOk()->assertJsonPath('data.status', 'PREVIEWED');
        $token = $preview->json('data.token');
        $this->postJson($prefix.'/'.$token.'/confirm', ['confirmed' => true,
            'reason' => 'Cap nhat ban nhap rieng giuong B'])->assertOk()
            ->assertJsonPath('data.status', 'UPDATED');
        $this->assertDatabaseHas('invoices', ['id' => $idA, 'status' => 'UNPAID', 'total_amount' => 1500000]);
        $this->assertDatabaseHas('invoices', ['id' => $idB, 'status' => 'DRAFT', 'total_amount' => 1700000]);
    }

    public function test_shared_meter_does_not_block_issuing_inclusive_bed_rent(): void
    {
        [$room, $a] = $this->setupBeds();
        $service = $room->boardingHouse->services()->create(['name' => 'Dien chung',
            'type' => 'ELECTRICITY', 'billing_method' => 'PER_UNIT', 'base_price' => 3800,
            'unit' => 'kWh', 'is_active' => true]);
        $room->utilityMeters()->create(['service_id' => $service->id, 'is_active' => true]);
        $id = $this->postJson('/api/billing-v2/contracts/'.$a->id.'/bed-invoices',
            ['billing_period' => '2026-11-01'])->assertOk()->json('data.invoices.0.id');
        $this->postJson('/api/billing-v2/invoices/'.$id.'/issue')->assertOk();
        $this->assertDatabaseHas('invoices', ['id' => $id, 'status' => 'UNPAID', 'total_amount' => 1500000]);
        $this->assertDatabaseCount('utility_billing_periods', 0);
    }
}
