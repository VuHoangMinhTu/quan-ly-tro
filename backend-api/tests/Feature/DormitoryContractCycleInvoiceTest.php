<?php

namespace Tests\Feature;

use App\Models\Contract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DormitoryContractCycleInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_bed_starting_on_november_four_has_full_month_invoice_without_manual_rent_confirmation(): void
    {
        $contract = Contract::factory()->create([
            'status' => 'ACTIVE',
            'start_date' => '2026-11-04',
            'end_date' => null,
            'monthly_rent' => 1500000,
        ]);
        $room = $contract->room;
        $room->update(['room_type' => 'DORMITORY']);
        $bed = $room->beds()->create([
            'bed_code' => 'KT-01',
            'monthly_rent' => 1500000,
            'status' => 'OCCUPIED',
        ]);
        $contract->forceFill([
            'contract_type' => 'DORM_BED',
            'bed_id' => $bed->id,
            'service_terms_version' => 1,
        ])->save();
        $contract->handoverReports()->create([
            'type' => 'CHECK_IN',
            'status' => 'CONFIRMED',
            'items' => [],
            'confirmed_at' => now(),
        ]);

        $owner = $room->boardingHouse->landlord->user;
        $this->actingAs($owner, 'sanctum');
        config(['billing.v2_pilot_user_ids' => [$owner->id]]);

        $url = '/api/billing-v2/boarding-houses/'.$room->boarding_house_id.'/monthly-invoices';
        $payload = [
            'billing_period' => '2026-11-01',
            'usage_start_at' => '2026-11-04 00:00:00',
            'usage_end_at' => '2026-12-04 00:00:00',
        ];
        $response = $this->postJson($url, $payload)->assertOk();
        $this->assertSame('CREATED', collect($response->json('data'))->firstWhere('room_id', $room->id)['status']);
        $this->assertDatabaseHas('invoices', [
            'room_id' => $room->id,
            'contract_id' => $contract->id,
            'billing_period' => '2026-11-01',
            'total_amount' => 1500000,
            'status' => 'DRAFT',
        ]);
        $this->postJson($url, $payload)->assertOk();
        $this->assertSame(1, $room->invoices()->count());
    }
}
