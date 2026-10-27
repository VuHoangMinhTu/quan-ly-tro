<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ContractServiceTermsApiTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $method = 'PER_UNIT', string $start = '2027-01-01'): array
    {
        $contract = Contract::factory()->create(['start_date' => $start, 'end_date' => '2027-12-31', 'monthly_rent' => 3800000]);
        $contract->handoverReports()->create(['type' => 'CHECK_IN', 'status' => 'CONFIRMED', 'items' => [['name' => 'Giường', 'quantity' => 1, 'condition' => 'GOOD', 'note' => null]], 'confirmed_at' => now()]);
        $owner = $contract->room->boardingHouse->landlord->user;
        $service = $contract->room->boardingHouse->services()->create([
            'name' => 'Điện thỏa thuận', 'type' => 'ELECTRICITY', 'billing_method' => $method,
            'base_price' => $method === 'TIERED' ? null : 4000, 'vat_rate' => 8, 'unit' => 'kWh', 'is_active' => true,
        ]);
        $contract->room->services()->attach($service, ['is_active' => true]);
        if ($method === 'TIERED') {
            $service->priceTiers()->createMany([
                ['from_quantity' => 0, 'to_quantity' => 50, 'unit_price' => 3000, 'tier_order' => 1],
                ['from_quantity' => 50, 'to_quantity' => null, 'unit_price' => 4000, 'tier_order' => 2],
            ]);
        }
        $meter = $contract->room->utilityMeters()->create(['service_id' => $service->id, 'initial_reading' => 0, 'is_active' => true]);
        $meter->readings()->createMany([
            ['reading_date' => '2027-04-30', 'reading_value' => 100],
            ['reading_date' => '2027-05-31', 'reading_value' => 120],
            ['reading_date' => '2027-06-30', 'reading_value' => 150],
        ]);
        $this->actingAs($owner, 'sanctum');

        return [$contract, $owner, $service, $meter];
    }

    private function activate(Contract $contract, User $owner, Service $service): void
    {
        $this->putJson("/api/contracts/{$contract->id}", [
            'status' => 'ACTIVE', 'lessor_representative_user_id' => $owner->id,
            'services' => [['service_id' => $service->id]],
        ])->assertOk();
    }

    private function invoice(Contract $contract, string $month): Invoice
    {
        return $contract->invoices()->create([
            'room_id' => $contract->room_id, 'invoice_code' => 'INV-'.str_replace('-', '', $month),
            'billing_period' => $month, 'status' => 'UNPAID', 'subtotal' => 0,
            'discount_amount' => 0, 'total_amount' => 0, 'paid_amount' => 0,
        ]);
    }

    private function adjust(Contract $contract, int $termId, array $data = [])
    {
        return $this->postJson("/api/contracts/{$contract->id}/service-adjustments", [
            'contract_service_id' => $termId, 'effective_from' => '2027-06-01',
            'base_price' => 4500, 'vat_rate' => 10, 'reason' => 'Điều chỉnh giá điện', ...$data,
        ]);
    }

    public function test_term_endpoints_require_authentication(): void
    {
        $this->getJson('/api/contracts/1/services')->assertUnauthorized();
        $this->putJson('/api/contracts/1/services', ['services' => []])->assertUnauthorized();
        $this->postJson('/api/contracts/1/service-adjustments', [])->assertUnauthorized();
        $this->postJson('/api/contracts/1/services/1/stop', [])->assertUnauthorized();
    }

    public function test_adjustment_closes_previous_period_without_overwriting_price_or_signing_snapshot(): void
    {
        [$contract, $owner, $service] = $this->fixture();
        $this->activate($contract, $owner, $service);
        $old = $contract->agreedServices()->sole();
        $signing = $contract->fresh()->signing_snapshot;

        $this->adjust($contract, $old->id)->assertCreated()->assertJsonPath('data', null);

        $this->assertDatabaseHas('contract_services', ['id' => $old->id, 'base_price' => 4000, 'vat_rate' => 8, 'effective_from' => '2027-01-01', 'effective_to' => '2027-05-31']);
        $this->assertDatabaseHas('contract_services', ['contract_id' => $contract->id, 'base_price' => 4500, 'vat_rate' => 10, 'effective_from' => '2027-06-01', 'effective_to' => null, 'created_by_user_id' => $owner->id, 'adjustment_reason' => 'Điều chỉnh giá điện']);
        $this->assertSame($signing, $contract->fresh()->signing_snapshot);
        $this->putJson("/api/contracts/{$contract->id}/services", ['services' => []])->assertUnprocessable();
        $this->putJson("/api/contracts/{$contract->id}", ['services' => []])->assertUnprocessable();
    }

    public function test_billing_uses_historical_contract_price_and_period_without_vat_when_regenerated_later(): void
    {
        [$contract, $owner, $service] = $this->fixture();
        $this->activate($contract, $owner, $service);
        $this->adjust($contract, $contract->agreedServices()->sole()->id)->assertCreated();
        $service->update(['base_price' => 9999, 'vat_rate' => 20, 'name' => 'Tên mới']);
        $may = $this->invoice($contract, '2027-05-01');
        $june = $this->invoice($contract, '2027-06-01');

        $this->postJson("/api/invoices/{$may->id}/generate")->assertOk();
        $this->postJson("/api/invoices/{$june->id}/generate")->assertOk();

        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $may->id, 'type' => 'ELECTRICITY', 'quantity' => 20, 'unit_price' => 4000, 'amount' => 80000]);
        $this->assertDatabaseMissing('invoice_items', ['invoice_id' => $may->id, 'type' => 'SERVICE_TAX']);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $june->id, 'type' => 'ELECTRICITY', 'quantity' => 30, 'unit_price' => 4500, 'amount' => 135000]);
        $this->assertDatabaseMissing('invoice_items', ['invoice_id' => $june->id, 'type' => 'SERVICE_TAX']);
        $juneItems = $june->items()->get()->toArray();
        $this->travelTo('2027-07-15');
        $this->postJson("/api/invoices/{$may->id}/generate")->assertOk();
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $may->id, 'type' => 'ELECTRICITY', 'unit_price' => 4000]);
        $this->assertSame($juneItems, $june->items()->get()->toArray());
    }

    public function test_tiered_billing_uses_frozen_tiers_not_master_price_tiers(): void
    {
        [$contract, $owner, $service] = $this->fixture('TIERED');
        $this->activate($contract, $owner, $service);
        $service->priceTiers()->update(['unit_price' => 9000]);
        $invoice = $this->invoice($contract, '2027-05-01');

        $this->postJson("/api/invoices/{$invoice->id}/generate")->assertOk();

        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'ELECTRICITY', 'amount' => 60000]);
        $this->assertDatabaseMissing('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'SERVICE_TAX']);
    }

    public function test_tier_adjustment_changes_only_future_tier_snapshot_and_vat(): void
    {
        [$contract, $owner, $service] = $this->fixture('TIERED');
        $this->activate($contract, $owner, $service);
        $original = $contract->agreedServices()->sole();
        $tiers = $original->pricing_snapshot;
        $this->adjust($contract, $original->id, ['base_price' => null, 'pricing_snapshot' => [['from_quantity' => 0, 'to_quantity' => null, 'unit_price' => 5000]]])->assertCreated();
        $may = $this->invoice($contract, '2027-05-01');
        $june = $this->invoice($contract, '2027-06-01');

        $this->postJson("/api/invoices/{$may->id}/generate")->assertOk();
        $this->postJson("/api/invoices/{$june->id}/generate")->assertOk();

        $this->assertSame($tiers, $original->fresh()->pricing_snapshot);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $may->id, 'type' => 'ELECTRICITY', 'amount' => 60000]);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $june->id, 'type' => 'ELECTRICITY', 'amount' => 150000]);
        $this->assertDatabaseMissing('invoice_items', ['invoice_id' => $june->id, 'type' => 'SERVICE_TAX']);
    }

    public function test_paid_period_blocks_stop_and_new_service_addition_too(): void
    {
        [$contract, $owner, $service] = $this->fixture('FIXED');
        $this->activate($contract, $owner, $service);
        $term = $contract->agreedServices()->sole();
        $invoice = $this->invoice($contract, '2027-06-01');
        $invoice->update(['status' => 'PAID', 'paid_amount' => 100, 'total_amount' => 100]);
        $internet = $contract->room->boardingHouse->services()->create(['name' => 'Internet', 'type' => 'INTERNET', 'billing_method' => 'FIXED', 'base_price' => 150000, 'is_active' => true]);
        $contract->room->services()->attach($internet, ['is_active' => true]);

        $this->postJson("/api/contracts/{$contract->id}/services/{$term->id}/stop", ['effective_from' => '2027-06-01', 'reason' => 'Ngừng'])->assertUnprocessable();
        $this->postJson("/api/contracts/{$contract->id}/service-adjustments", ['service_id' => $internet->id, 'effective_from' => '2027-06-01', 'reason' => 'Thêm'])->assertUnprocessable();

        $this->assertSame(1, $contract->agreedServices()->count());
        $this->assertNull($term->fresh()->effective_to);
    }

    public function test_draft_can_edit_price_remove_and_explicitly_sync_but_activation_does_not_overwrite_reviewed_price(): void
    {
        [$contract, $owner, $service] = $this->fixture('FIXED');
        $this->putJson("/api/contracts/{$contract->id}/services", ['services' => [['service_id' => $service->id, 'base_price' => 12345, 'vat_rate' => 0]]])->assertOk();
        $service->update(['base_price' => 50000]);
        $this->putJson("/api/contracts/{$contract->id}", ['status' => 'ACTIVE', 'lessor_representative_user_id' => $owner->id])->assertOk();

        $this->assertDatabaseHas('contract_services', ['contract_id' => $contract->id, 'base_price' => 12345]);
        $invoice = $this->invoice($contract, '2027-05-01');
        $this->postJson("/api/invoices/{$invoice->id}/generate")->assertOk();
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'ELECTRICITY', 'amount' => 12345]);
        $other = Contract::factory()->create(['room_id' => $contract->room_id, 'tenant_id' => $contract->tenant_id]);
        $this->putJson("/api/contracts/{$other->id}/services", ['services' => []])->assertOk();
        $this->assertSame(0, $other->agreedServices()->count());
        $this->putJson("/api/contracts/{$other->id}/services", ['sync_from_room' => true])->assertOk();
        $this->assertSame('50000.00', $other->agreedServices()->sole()->base_price);
    }

    public function test_add_stop_and_readd_only_bill_periods_where_service_is_active(): void
    {
        [$contract, $owner, $electricity] = $this->fixture('FIXED');
        $this->activate($contract, $owner, $electricity);
        $internet = $contract->room->boardingHouse->services()->create(['name' => 'Internet', 'type' => 'INTERNET', 'billing_method' => 'FIXED', 'base_price' => 150000, 'vat_rate' => 0, 'is_active' => true]);
        $contract->room->services()->attach($internet, ['is_active' => true]);
        $this->postJson("/api/contracts/{$contract->id}/service-adjustments", ['service_id' => $internet->id, 'effective_from' => '2027-06-01', 'reason' => 'Thêm Internet'])->assertCreated();
        $term = $contract->agreedServices()->where('service_id', $internet->id)->sole();
        $this->postJson("/api/contracts/{$contract->id}/services/{$term->id}/stop", ['effective_from' => '2027-09-01', 'reason' => 'Ngừng Internet'])->assertOk();
        $this->assertDatabaseHas('contract_services', ['id' => $term->id, 'effective_to' => '2027-08-31', 'base_price' => 150000]);
        $this->postJson("/api/contracts/{$contract->id}/service-adjustments", ['service_id' => $internet->id, 'effective_from' => '2027-11-01', 'reason' => 'Dùng lại Internet'])->assertCreated();

        foreach (['2027-05-01' => false, '2027-06-01' => true, '2027-08-01' => true, '2027-09-01' => false, '2027-11-01' => true] as $month => $expected) {
            $invoice = $this->invoice($contract, $month);
            $this->postJson("/api/invoices/{$invoice->id}/generate")->assertOk();
            $this->assertSame($expected, $invoice->items()->where('type', 'INTERNET')->exists(), $month);
        }
    }

    #[TestWith(['PAID', 100])]
    #[TestWith(['PARTIALLY_PAID', 50])]
    public function test_paid_history_prevents_retroactive_changes_and_keeps_terms_and_items(string $status, int $paid): void
    {
        [$contract, $owner, $service] = $this->fixture();
        $this->activate($contract, $owner, $service);
        $old = $contract->agreedServices()->sole();
        $invoice = $this->invoice($contract, '2027-06-01');
        $invoice->update(['status' => $status, 'paid_amount' => $paid, 'total_amount' => 100]);

        $this->adjust($contract, $old->id)->assertUnprocessable()->assertJsonPath('errors.effective_from.0', 'Không thể điều chỉnh dịch vụ từ kỳ này vì đã có hóa đơn phát sinh thanh toán.');

        $this->assertSame(1, $contract->agreedServices()->count());
        $this->assertNull($old->fresh()->effective_to);
        $this->assertSame($status, $invoice->fresh()->status);
    }

    #[TestWith(['DRAFT'])]
    #[TestWith(['UNPAID'])]
    public function test_unpaid_existing_invoice_requires_explicit_resolution_before_adjustment(string $status): void
    {
        [$contract, $owner, $service] = $this->fixture();
        $this->activate($contract, $owner, $service);
        $invoice = $this->invoice($contract, '2027-06-01');
        $invoice->update(['status' => $status]);

        $this->adjust($contract, $contract->agreedServices()->sole()->id)->assertUnprocessable()->assertJsonValidationErrors('effective_from');

        $this->assertSame(1, $contract->agreedServices()->count());
        $this->assertSame($status, $invoice->fresh()->status);
    }

    #[TestWith(['2027-06-15'])]
    #[TestWith(['2026-12-01'])]
    #[TestWith(['2028-01-01'])]
    #[TestWith(['2027-01-01'])]
    public function test_adjustment_rejects_non_month_start_outside_contract_and_overlapping_start(string $date): void
    {
        [$contract, $owner, $service] = $this->fixture();
        $this->activate($contract, $owner, $service);

        $this->adjust($contract, $contract->agreedServices()->sole()->id, ['effective_from' => $date])->assertUnprocessable()->assertJsonValidationErrors('effective_from');

        $this->assertSame(1, $contract->agreedServices()->count());
    }

    public function test_future_versions_cannot_be_overwritten_or_overlapped_by_concurrent_retry(): void
    {
        [$contract, $owner, $service] = $this->fixture();
        $this->activate($contract, $owner, $service);
        $id = $contract->agreedServices()->sole()->id;
        $this->adjust($contract, $id)->assertCreated();

        $this->adjust($contract, $id)->assertUnprocessable();
        $this->adjust($contract, $id, ['effective_from' => '2027-04-01'])->assertUnprocessable();
        $this->postJson("/api/contracts/{$contract->id}/service-adjustments", ['service_id' => $service->id, 'effective_from' => '2027-07-01', 'reason' => 'Trùng'])->assertUnprocessable();

        $this->assertSame(2, $contract->agreedServices()->count());
    }

    public function test_initial_mid_month_term_keeps_contract_start_and_bills_first_month_without_normalizing_date(): void
    {
        [$contract, $owner, $service] = $this->fixture('FIXED', '2027-01-15');
        $this->activate($contract, $owner, $service);
        $invoice = $this->invoice($contract, '2027-01-01');

        $this->postJson("/api/invoices/{$invoice->id}/generate")->assertOk();

        $this->assertSame('2027-01-15', $contract->agreedServices()->sole()->effective_from->format('Y-m-d'));
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'ELECTRICITY', 'amount' => 4000]);
    }

    public function test_room_assignment_still_bills_when_new_contract_has_no_service_terms(): void
    {
        [$legacy, $owner, $service] = $this->fixture('FIXED');
        $invoice = $this->invoice($legacy, '2027-05-01');
        $this->getJson("/api/contracts/{$legacy->id}/services")->assertOk()->assertJsonPath('data.legacy_fallback', true);
        $this->postJson("/api/invoices/{$invoice->id}/generate")->assertOk();
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'ELECTRICITY', 'amount' => 4000]);
        $invoice->delete();
        $this->putJson("/api/contracts/{$legacy->id}", ['status' => 'ACTIVE', 'lessor_representative_user_id' => $owner->id, 'services' => []])->assertOk();
        $next = $this->invoice($legacy, '2027-06-01');

        $this->postJson("/api/invoices/{$next->id}/generate")->assertOk();

        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $next->id, 'type' => 'ELECTRICITY', 'amount' => 4000]);
        $this->getJson("/api/contracts/{$legacy->id}/services")->assertJsonPath('data.legacy_fallback', false);
    }

    public function test_missing_new_snapshot_data_fails_without_falling_back_or_deleting_manual_items(): void
    {
        [$contract, $owner, $service] = $this->fixture();
        $this->activate($contract, $owner, $service);
        $contract->agreedServices()->delete();
        $invoice = $this->invoice($contract, '2027-05-01');
        $manual = $invoice->items()->create(['type' => 'OTHER', 'description' => 'Thủ công', 'quantity' => 1, 'unit_price' => 100, 'amount' => 100, 'source' => 'MANUAL']);

        $this->postJson("/api/invoices/{$invoice->id}/generate")->assertUnprocessable()->assertJsonValidationErrors('services');

        $this->assertModelExists($manual);
        $this->assertDatabaseMissing('invoice_items', ['invoice_id' => $invoice->id, 'source' => 'AUTO']);
    }

    public function test_soft_deleted_service_is_not_billed_and_missing_source_does_not_use_another_meter(): void
    {
        [$contract, $owner, $service] = $this->fixture();
        $this->activate($contract, $owner, $service);
        $service->delete();
        $invoice = $this->invoice($contract, '2027-05-01');
        $this->postJson("/api/invoices/{$invoice->id}/generate")->assertOk();
        $this->assertDatabaseMissing('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'ELECTRICITY']);
        $service->restore();
        $contract->agreedServices()->update(['service_id' => null]);

        $this->postJson("/api/invoices/{$invoice->id}/generate")->assertUnprocessable()->assertJsonValidationErrors('service_id');

        $this->assertDatabaseMissing('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'ELECTRICITY']);
    }

    public function test_per_person_snapshot_counts_contract_occupants_in_the_period_even_if_they_later_moved_out(): void
    {
        [$contract, $owner, $service] = $this->fixture('PER_PERSON');
        $this->activate($contract, $owner, $service);
        $contract->occupants()->update(['status' => 'MOVED_OUT', 'move_out_date' => '2027-05-31']);
        $service->update(['base_price' => 9000]);
        $invoice = $this->invoice($contract, '2027-05-01');

        $this->postJson("/api/invoices/{$invoice->id}/generate")->assertOk();

        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'ELECTRICITY', 'quantity' => 1, 'unit_price' => 4000, 'amount' => 4000]);
    }

    public function test_manager_can_adjust_assigned_house_but_foreign_contract_and_nested_term_return_404(): void
    {
        [$contract, $owner, $service] = $this->fixture();
        $this->activate($contract, $owner, $service);
        $manager = User::factory()->create();
        $contract->room->boardingHouse->managers()->attach($manager);
        $term = $contract->agreedServices()->sole();
        $foreign = Contract::factory()->create();
        $this->actingAs($manager, 'sanctum');

        $this->getJson("/api/contracts/{$contract->id}/services")->assertOk();
        $this->adjust($contract, $term->id)->assertCreated();
        $this->getJson("/api/contracts/{$foreign->id}/services")->assertNotFound();
        $this->adjust($foreign, $term->id)->assertNotFound();
        $this->postJson("/api/contracts/{$contract->id}/services/999999/stop", ['effective_from' => '2027-08-01', 'reason' => 'Sai'])->assertNotFound();
        $contract->room->boardingHouse->managers()->detach($manager);
        $this->getJson("/api/contracts/{$contract->id}/services")->assertNotFound();
    }

    #[TestWith(['EXPIRED'])]
    #[TestWith(['TERMINATED'])]
    public function test_ended_contracts_are_read_only(string $status): void
    {
        [$contract, $owner, $service] = $this->fixture();
        $this->activate($contract, $owner, $service);
        $term = $contract->agreedServices()->sole();
        $contract->update(['status' => $status]);

        $this->getJson("/api/contracts/{$contract->id}/services")->assertOk();
        $this->adjust($contract, $term->id)->assertUnprocessable();
        $this->putJson("/api/contracts/{$contract->id}/services", ['sync_from_room' => true])->assertUnprocessable();
        $this->postJson("/api/contracts/{$contract->id}/services/{$term->id}/stop", ['effective_from' => '2027-08-01', 'reason' => 'Sai'])->assertUnprocessable();
        $this->assertNull($term->fresh()->effective_to);
    }

    public function test_invalid_tier_gaps_and_unauthorized_pricing_metadata_are_not_accepted(): void
    {
        [$contract, $owner, $service] = $this->fixture('TIERED');
        $this->putJson("/api/contracts/{$contract->id}/services", ['services' => [[
            'service_id' => $service->id, 'pricing_snapshot' => [['from_quantity' => 1, 'to_quantity' => null, 'unit_price' => 1]],
        ]]])->assertUnprocessable()->assertJsonValidationErrors('services.0.pricing_snapshot');
        $this->putJson("/api/contracts/{$contract->id}/services", ['services' => [[
            'service_id' => $service->id, 'service_name' => 'Fake', 'service_type' => 'OTHER', 'created_by_user_id' => 9999, 'effective_from' => '2000-01-01',
        ]]])->assertOk();

        $this->assertDatabaseHas('contract_services', ['contract_id' => $contract->id, 'service_name' => 'Điện thỏa thuận', 'service_type' => 'ELECTRICITY', 'created_by_user_id' => $owner->id, 'effective_from' => '2027-01-01']);
    }

    public function test_migration_preserves_initial_prices_and_only_backfills_known_start_dates(): void
    {
        $migration = require database_path('migrations/2026_10_18_084102_version_contract_service_terms.php');
        $migration->down();
        $contract = Contract::factory()->create(['start_date' => '2027-01-15']);
        $term = $contract->agreedServices()->create(['service_name' => 'Giá cũ', 'service_type' => 'INTERNET', 'billing_method' => 'FIXED', 'base_price' => 123456, 'vat_rate' => 8, 'is_active' => true]);
        $unknown = Contract::factory()->create(['start_date' => null]);
        $unknownTerm = $unknown->agreedServices()->create(['service_name' => 'Chưa rõ ngày', 'service_type' => 'INTERNET', 'billing_method' => 'FIXED', 'base_price' => 999, 'is_active' => true]);

        $migration->up();

        $this->assertDatabaseHas('contract_services', ['id' => $term->id, 'base_price' => 123456, 'vat_rate' => 8, 'effective_from' => '2027-01-15', 'effective_to' => null]);
        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'service_terms_version' => 1]);
        $this->assertDatabaseHas('contract_services', ['id' => $unknownTerm->id, 'base_price' => 999, 'effective_from' => null]);
        $this->assertDatabaseHas('contracts', ['id' => $unknown->id, 'service_terms_version' => null]);
    }
}
