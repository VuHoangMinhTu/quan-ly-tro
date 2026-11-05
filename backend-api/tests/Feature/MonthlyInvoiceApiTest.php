<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MonthlyInvoiceApiTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $c = Contract::factory()->create(['status' => 'ACTIVE', 'start_date' => '2026-10-01']);
        $c->forceFill(['service_terms_version' => 1])->save();
        $c->handoverReports()->create(['type' => 'CHECK_IN', 'status' => 'CONFIRMED', 'items' => [], 'confirmed_at' => now()]);
        $owner = $c->room->boardingHouse->landlord->user;
        $this->actingAs($owner, 'sanctum');
        config(['billing.v2_pilot_user_ids' => [$owner->id]]);

        return [$c, '/api/billing-v2/boarding-houses/'.$c->room->boarding_house_id.'/monthly-invoices'];
    }

    private function term(Contract $c, string $method, string $type = 'OTHER'): object
    {
        $s = $c->room->boardingHouse->services()->create(['name' => $method.' test', 'type' => $type, 'billing_method' => $method, 'base_price' => 10000, 'unit' => 'đơn vị', 'is_active' => true]);
        $c->room->services()->attach($s, ['is_active' => true]);
        if ($method === 'TIERED') {
            $s->priceTiers()->create(['from_quantity' => 0, 'to_quantity' => null, 'unit_price' => 2000, 'tier_order' => 1]);
        }

        return $c->agreedServices()->create(['service_id' => $s->id, 'service_name' => $s->name, 'service_type' => $type, 'billing_method' => $method, 'base_price' => $s->base_price, 'unit' => $s->unit, 'is_active' => true, 'effective_from' => '2026-10-01', 'pricing_snapshot' => $s->priceTiers->toArray()]);
    }

    #[TestWith(['ELECTRICITY', 'TIERED', 3845000])]
    #[TestWith(['WATER', 'PER_UNIT', 4005000])]
    public function test_confirmed_recalculation_uses_new_meter_evidence_preserves_manual_and_replays(string $type, string $method, int $expected): void
    {
        [$c, $url] = $this->fixture();
        $term = $this->term($c, $method, $type);
        $meter = $c->room->utilityMeters()->create(['service_id' => $term->service_id, 'is_active' => true]);
        foreach ([['PERIOD_BOUNDARY', null, '2026-10-01 00:00:00', 100], ['CHECK_IN', $c->id, '2026-10-01 00:00:00', 100], ['PERIOD_BOUNDARY', null, '2026-11-01 00:00:00', 115]] as [$kind, $contract, $at, $value]) {
            $this->postJson('/api/billing-v2/meters/'.$meter->id.'/checkpoints', ['kind' => $kind, 'contract_id' => $contract, 'observed_at' => $at, 'reading_value' => $value])->assertOk();
        }
        $id = $this->postJson($url, ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->json('data.0.invoices.0.id');
        $this->postJson('/api/invoices/'.$id.'/items', ['type' => 'OTHER', 'description' => 'Manual preserved', 'quantity' => 1, 'unit_price' => 5000])->assertCreated();
        $manualId = Invoice::findOrFail($id)->items()->where('source', 'MANUAL')->value('id');
        $old = Invoice::findOrFail($id)->total_amount;
        $this->postJson('/api/billing-v2/meters/'.$meter->id.'/checkpoints', ['kind' => 'PERIOD_BOUNDARY', 'observed_at' => '2026-11-01 00:00:00', 'reading_value' => 120])->assertOk();
        $prefix = '/api/billing-v2/rooms/'.$c->room_id.'/monthly-recalculations';
        $preview = $this->postJson($prefix.'/preview', ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonPath('data.status', 'PREVIEWED');
        $this->assertSame($old, Invoice::findOrFail($id)->total_amount, 'Preview cannot commit money changes.');
        $this->assertDatabaseCount('invoice_items', 3);
        $token = $preview->json('data.token');
        $payload = ['confirmed' => true, 'reason' => 'Sửa chỉ số chốt theo bằng chứng'];
        $this->postJson($prefix.'/'.$token.'/confirm', $payload)->assertOk()->assertJsonPath('data.replayed', false);
        $ids = Invoice::findOrFail($id)->items()->pluck('id')->all();
        $this->postJson($prefix.'/'.$token.'/confirm', $payload)->assertOk()->assertJsonPath('data.replayed', true);
        $this->postJson($prefix.'/'.$token.'/confirm', [...$payload, 'reason' => 'Lý do khác của yêu cầu mới'])->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->assertSame($ids, Invoice::findOrFail($id)->items()->pluck('id')->all());
        $this->assertDatabaseHas('invoices', ['id' => $id, 'total_amount' => $expected, 'status' => 'DRAFT']);
        $this->assertDatabaseHas('invoice_items', ['id' => $manualId, 'source' => 'MANUAL', 'amount' => 5000]);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_items', 3);
        $this->assertDatabaseHas('monthly_draft_recalculations', ['token' => $token, 'status' => 'APPLIED', 'reason' => $payload['reason']]);
        $this->getJson('/api/invoices/'.$id)->assertOk()->assertJsonCount(1, 'data.monthly_recalculations');
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payos_payment_requests', 0);
    }

    public function test_changed_source_after_preview_requires_new_preview(): void
    {
        [$c, $url] = $this->fixture();
        $this->postJson($url, ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk();
        $prefix = '/api/billing-v2/rooms/'.$c->room_id.'/monthly-recalculations';
        $token = $this->postJson($prefix.'/preview', ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->json('data.token');
        $c->update(['monthly_rent' => 4000000]);
        $this->postJson($prefix.'/'.$token.'/confirm', ['confirmed' => true, 'reason' => 'Xác nhận cập nhật'])->assertUnprocessable()->assertJsonValidationErrors('stale_preview');
        $this->assertDatabaseHas('invoices', ['total_amount' => 3800000]);
        $this->assertDatabaseMissing('monthly_draft_recalculations', ['status' => 'APPLIED']);
    }

    public function test_manual_change_after_preview_blocks_stale_confirm(): void
    {
        [$c, $url] = $this->fixture();
        $id = $this->postJson($url, ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->json('data.0.invoices.0.id');
        $prefix = '/api/billing-v2/rooms/'.$c->room_id.'/monthly-recalculations';
        $token = $this->postJson($prefix.'/preview', ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->json('data.token');
        $this->postJson('/api/invoices/'.$id.'/items', ['type' => 'OTHER', 'description' => 'Manual after preview', 'quantity' => 1, 'unit_price' => 5000])->assertCreated();
        $this->postJson($prefix.'/'.$token.'/confirm', ['confirmed' => true, 'reason' => 'Xác nhận cập nhật'])->assertUnprocessable()->assertJsonValidationErrors('stale_preview');
        $this->assertDatabaseHas('invoices', ['total_amount' => 3805000]);
    }

    #[TestWith(['UNPAID'])]
    #[TestWith(['PAID'])]
    public function test_issued_documents_cannot_be_recalculated(string $status): void
    {
        [$c, $url] = $this->fixture();
        $id = $this->postJson($url, ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->json('data.0.invoices.0.id');
        $prefix = '/api/billing-v2/rooms/'.$c->room_id.'/monthly-recalculations';
        $token = $this->postJson($prefix.'/preview', ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->json('data.token');
        Invoice::findOrFail($id)->update(['status' => $status, 'issued_at' => '2026-11-01']);
        $this->postJson('/api/billing-v2/rooms/'.$c->room_id.'/monthly-recalculations/preview', ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonPath('data.status', 'BLOCKED');
        $this->assertDatabaseHas('invoices', ['id' => $id, 'total_amount' => 3800000, 'status' => $status]);
        $this->postJson($prefix.'/'.$token.'/confirm', ['confirmed' => true, 'reason' => 'Không được sửa chứng từ cũ'])->assertUnprocessable();
        $this->assertDatabaseMissing('monthly_draft_recalculations', ['status' => 'APPLIED']);
    }

    public function test_new_meter_missing_checkpoints_keeps_existing_draft_intact(): void
    {
        [$c, $url] = $this->fixture();
        $this->postJson($url, ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk();
        $term = $this->term($c, 'PER_UNIT', 'WATER');
        $c->room->utilityMeters()->create(['service_id' => $term->service_id, 'is_active' => true]);
        $this->postJson('/api/billing-v2/rooms/'.$c->room_id.'/monthly-recalculations/preview', ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonPath('data.status', 'PENDING_FINAL_SETTLEMENT');
        $this->assertDatabaseHas('invoices', ['total_amount' => 3800000]);
        $this->assertDatabaseCount('invoice_items', 1);
    }

    public function test_recalculation_requires_owner_and_explicit_confirmation(): void
    {
        [$c, $url] = $this->fixture();
        $this->postJson($url, ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk();
        $prefix = '/api/billing-v2/rooms/'.$c->room_id.'/monthly-recalculations';
        $token = $this->postJson($prefix.'/preview', ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->json('data.token');
        $this->postJson($prefix.'/'.$token.'/confirm', ['reason' => 'Có lý do nhưng chưa xác nhận'])->assertUnprocessable();
        $otherUser = User::factory()->create();
        $this->actingAs($otherUser, 'sanctum');
        config(['billing.v2_pilot_user_ids' => [$otherUser->id]]);
        $this->postJson($prefix.'/preview', ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertNotFound();
        $this->postJson($prefix.'/'.$token.'/confirm', ['confirmed' => true, 'reason' => 'Không có quyền sở hữu'])->assertNotFound();
    }

    public function test_updated_agreed_price_is_previewed_and_confirmed_but_missing_source_is_blocked(): void
    {
        [$c, $url] = $this->fixture();
        $term = $this->term($c, 'FIXED');
        $this->postJson($url, ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk();
        $term->update(['base_price' => 12000]);
        $id = $c->room->invoices()->firstOrFail()->id;
        $this->postJson('/api/billing-v2/invoices/'.$id.'/issue')->assertUnprocessable()->assertJsonValidationErrors('stale_monthly_sources');
        $prefix = '/api/billing-v2/rooms/'.$c->room_id.'/monthly-recalculations';
        $preview = $this->postJson($prefix.'/preview', ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonPath('data.status', 'PREVIEWED');
        $this->assertDatabaseHas('invoices', ['total_amount' => 3810000]);
        $this->postJson($prefix.'/'.$preview->json('data.token').'/confirm', ['confirmed' => true, 'reason' => 'Đối soát đơn giá đã thỏa thuận'])->assertOk();
        $this->assertDatabaseHas('invoices', ['total_amount' => 3812000]);
        $term->delete();
        $this->postJson($prefix.'/preview', ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonPath('data.status', 'BLOCKED');
        $this->assertDatabaseHas('invoices', ['total_amount' => 3812000]);
    }

    public function test_payment_request_blocks_recalculation_without_contacting_provider(): void
    {
        [$c, $url] = $this->fixture();
        $id = $this->postJson($url, ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->json('data.0.invoices.0.id');
        Invoice::findOrFail($id)->payosPaymentRequests()->create(['amount' => 3800000, 'status' => 'PENDING']);
        $this->postJson('/api/billing-v2/rooms/'.$c->room_id.'/monthly-recalculations/preview', ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonPath('data.status', 'BLOCKED');
        $this->assertDatabaseHas('invoices', ['id' => $id, 'total_amount' => 3800000]);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_one_monthly_invoice_contains_all_five_automatic_methods(): void
    {
        [$c, $url] = $this->fixture();
        $this->term($c, 'FIXED', 'INTERNET');
        $this->term($c, 'PER_PERSON', 'OTHER');
        $c->occupants()->create(['room_id' => $c->room_id, 'tenant_id' => $c->tenant_id, 'move_in_date' => '2026-10-01', 'status' => 'ACTIVE', 'is_primary' => true]);
        foreach ([['TIERED', 'ELECTRICITY'], ['PER_UNIT', 'WATER']] as [$method, $type]) {
            $term = $this->term($c, $method, $type);
            $meter = $c->room->utilityMeters()->create(['service_id' => $term->service_id, 'is_active' => true]);
            foreach ([['PERIOD_BOUNDARY', null, '2026-10-01 00:00:00', 100], ['CHECK_IN', $c->id, '2026-10-01 00:00:00', 100], ['PERIOD_BOUNDARY', null, '2026-11-01 00:00:00', 115]] as [$kind, $contract, $at, $value]) {
                $this->postJson('/api/billing-v2/meters/'.$meter->id.'/checkpoints', ['kind' => $kind, 'contract_id' => $contract, 'observed_at' => $at, 'reading_value' => $value])->assertOk();
            }
        }
        $id = $this->postJson($url, ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonPath('data.0.status', 'CREATED')->json('data.0.invoices.0.id');
        $this->assertDatabaseHas('invoices', ['id' => $id, 'total_amount' => 4000000, 'status' => 'DRAFT']);
        $this->assertDatabaseCount('invoice_items', 5);
        $this->postJson($url, ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonPath('data.0.status', 'EXISTING');
        $this->assertDatabaseCount('invoice_items', 5);
    }

    public function test_full_month_rent_fixed_person_draft_and_repeated_request_preserves_manual(): void
    {
        [$c, $url] = $this->fixture();
        $this->term($c, 'FIXED');
        $this->term($c, 'PER_PERSON');
        $c->occupants()->create(['room_id' => $c->room_id, 'tenant_id' => $c->tenant_id, 'move_in_date' => '2026-10-01', 'status' => 'ACTIVE', 'is_primary' => true]);
        $response = $this->postJson($url, ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonPath('data.0.status', 'CREATED')->assertJsonPath('data.0.invoices.0.status', 'DRAFT');
        $id = $response->json('data.0.invoices.0.id');
        $this->assertDatabaseHas('invoices', ['id' => $id, 'total_amount' => 3820000, 'issued_at' => null]);
        $this->postJson('/api/invoices/'.$id.'/items', ['type' => 'OTHER', 'description' => 'Khoản thủ công', 'quantity' => 1, 'unit_price' => 5000])->assertCreated();
        $this->postJson($url, ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonPath('data.0.status', 'EXISTING');
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_items', 4);
        $this->postJson('/api/invoices/'.$id.'/generate')->assertUnprocessable()->assertJsonValidationErrors('invoice_id');
        $this->putJson('/api/invoices/'.$id, ['billing_period' => '2026-10-01', 'status' => 'UNPAID'])->assertUnprocessable()->assertJsonValidationErrors('invoice_id');
        $this->deleteJson('/api/invoices/'.$id)->assertUnprocessable()->assertJsonValidationErrors('invoice_id');
        $this->assertDatabaseHas('invoices', ['id' => $id, 'status' => 'DRAFT', 'total_amount' => 3825000]);
        $this->assertDatabaseCount('invoice_items', 4);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payos_payment_requests', 0);
    }

    public function test_legacy_invoice_create_cannot_create_second_document_for_v2_processed_period(): void
    {
        [$c, $url] = $this->fixture();
        $c->occupants()->create(['room_id' => $c->room_id, 'tenant_id' => $c->tenant_id, 'move_in_date' => '2026-10-01', 'status' => 'ACTIVE', 'is_primary' => true]);
        $this->postJson($url, ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonPath('data.0.status', 'CREATED');

        $this->postJson('/api/rooms/'.$c->room_id.'/invoices', [
            'contract_id' => $c->id, 'billing_period' => '2026-10-01', 'status' => 'DRAFT',
        ])->assertUnprocessable()->assertJsonValidationErrors('billing_period');

        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('monthly_invoice_compositions', 1);
    }

    #[TestWith(['PER_UNIT', '150000'])]
    #[TestWith(['TIERED', '30000'])]
    public function test_actual_meter_allocation_composes_with_rent(string $method, string $amount): void
    {
        [$c, $url] = $this->fixture();
        $term = $this->term($c, $method, 'ELECTRICITY');
        $meter = $c->room->utilityMeters()->create(['service_id' => $term->service_id, 'is_active' => true]);
        foreach ([['PERIOD_BOUNDARY', null, '2026-10-01 00:00:00', 100], ['CHECK_IN', $c->id, '2026-10-01 00:00:00', 100], ['PERIOD_BOUNDARY', null, '2026-11-01 00:00:00', 115]] as [$kind, $contract, $at, $value]) {
            $this->postJson('/api/billing-v2/meters/'.$meter->id.'/checkpoints', ['kind' => $kind, 'contract_id' => $contract, 'observed_at' => $at, 'reading_value' => $value])->assertOk();
        }
        $this->postJson($url, ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonPath('data.0.status', 'CREATED');
        $this->assertDatabaseHas('invoice_items', ['type' => 'ELECTRICITY', 'amount' => $amount]);
        $this->assertDatabaseHas('invoice_items', ['type' => 'RENT', 'amount' => 3800000]);
        $this->postJson('/api/billing-v2/rooms/'.$c->room_id.'/generate', ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertUnprocessable()->assertJsonValidationErrors('invoice_review');
        $this->assertDatabaseCount('invoice_items', 2);
    }

    public function test_missing_meter_returns_pending_without_zero_invoice(): void
    {
        [$c, $url] = $this->fixture();
        $this->term($c, 'PER_UNIT', 'WATER');
        $this->postJson($url, ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonPath('data.0.status', 'PENDING_FINAL_SETTLEMENT');
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_partial_period_requires_confirmation_then_replays_same_invoice(): void
    {
        [$c, $url] = $this->fixture();
        $c->update(['start_date' => '2026-10-20']);
        $term = $this->term($c, 'PER_PERSON');
        $this->postJson($url, ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonPath('data.0.status', 'BLOCKED');
        $this->assertDatabaseCount('invoices', 0);
        $data = ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00', 'confirmations' => [['contract_id' => $c->id, 'rent_amount' => '1200000', 'reason' => 'Theo thỏa thuận kỳ đầu', 'services' => [['term_id' => $term->id, 'quantity' => '2', 'amount' => '10000']]]]];
        $this->postJson($url, $data)->assertOk()->assertJsonPath('data.0.status', 'CREATED');
        $this->postJson($url, $data)->assertOk()->assertJsonPath('data.0.status', 'EXISTING');
        $this->assertDatabaseHas('invoices', ['total_amount' => 1210000]);
        $data['confirmations'][0]['rent_amount'] = '2000000';
        $this->postJson($url, $data)->assertOk()->assertJsonPath('data.0.status', 'BLOCKED');
        $this->assertDatabaseCount('invoices', 1);
    }

    #[TestWith(['UNPAID'])]
    #[TestWith(['PAID'])]
    #[TestWith(['DRAFT'])]
    public function test_legacy_documents_are_not_changed(string $status): void
    {
        [$c, $url] = $this->fixture();
        $invoice = $c->room->invoices()->create(['contract_id' => $c->id, 'billing_period' => '2026-10-01', 'status' => $status, 'total_amount' => 12345]);
        $this->postJson($url, ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonPath('data.0.status', 'BLOCKED');
        $this->assertSame('12345.00', $invoice->fresh()->total_amount);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_dormitory_two_beds_generate_only_their_contract_rent_without_meters_or_service_charges(): void
    {
        [$first, $url] = $this->fixture();
        $room = $first->room;
        $room->update(['room_type' => 'DORMITORY']);
        $bedA = $room->beds()->create(['bed_code' => 'A01', 'monthly_rent' => 1500000, 'status' => 'OCCUPIED']);
        $bedB = $room->beds()->create(['bed_code' => 'A02', 'monthly_rent' => 1700000, 'status' => 'OCCUPIED']);
        $first->update(['contract_type' => 'DORM_BED', 'bed_id' => $bedA->id, 'monthly_rent' => 1500000]);
        $term = $this->term($first, 'TIERED', 'ELECTRICITY');
        $room->utilityMeters()->create(['service_id' => $term->service_id, 'is_active' => true]);
        $second = Contract::factory()->create(['room_id' => $room->id, 'contract_type' => 'DORM_BED', 'bed_id' => $bedB->id,
            'status' => 'ACTIVE', 'start_date' => '2026-10-01', 'monthly_rent' => 1700000]);
        $second->handoverReports()->create(['type' => 'CHECK_IN', 'status' => 'CONFIRMED', 'items' => [], 'confirmed_at' => now()]);

        $payload = ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-02 00:00:00', 'usage_end_at' => '2026-11-02 00:00:00'];
        $response = $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.0.status', 'CREATED');
        $this->assertCount(2, $response->json('data.0.invoices'));
        $this->assertDatabaseHas('invoices', ['contract_id' => $first->id, 'total_amount' => 1500000, 'status' => 'DRAFT']);
        $this->assertDatabaseHas('invoices', ['contract_id' => $second->id, 'total_amount' => 1700000, 'status' => 'DRAFT']);
        $this->assertDatabaseCount('invoice_items', 2);
        $this->assertDatabaseCount('utility_billing_periods', 0);
        $this->assertDatabaseCount('utility_charge_allocations', 0);
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.0.status', 'EXISTING');
        $this->assertDatabaseCount('invoices', 2);
    }

    public function test_dormitory_optional_fixed_service_needs_explicit_agreement_confirmation(): void
    {
        [$contract, $url] = $this->fixture();
        $room = $contract->room;
        $room->update(['room_type' => 'DORMITORY']);
        $bed = $room->beds()->create(['bed_code' => 'B01', 'monthly_rent' => 1500000]);
        $contract->update(['contract_type' => 'DORM_BED', 'bed_id' => $bed->id, 'monthly_rent' => 1500000]);
        $term = $this->term($contract, 'FIXED', 'INTERNET');
        $payload = ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00',
            'confirmations' => [['contract_id' => $contract->id, 'reason' => 'Đã có thỏa thuận phí Internet riêng',
                'services' => [['term_id' => $term->id, 'quantity' => 1, 'amount' => 10000]]]]];
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.0.status', 'BLOCKED');
        $this->assertDatabaseCount('invoices', 0);

        $payload['confirmations'][0]['extra_service_confirmed'] = true;
        $id = $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.0.status', 'CREATED')->json('data.0.invoices.0.id');
        $this->assertDatabaseHas('invoices', ['id' => $id, 'total_amount' => 1510000]);
        $this->assertDatabaseCount('invoice_items', 2);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $id, 'type' => 'INTERNET', 'amount' => 10000]);
    }

    public function test_dormitory_draft_can_opt_in_extra_service_with_preview_and_preserve_manual_item(): void
    {
        [$contract, $url] = $this->fixture();
        $room = $contract->room;
        $room->update(['room_type' => 'DORMITORY']);
        $bed = $room->beds()->create(['bed_code' => 'D01', 'monthly_rent' => 1500000]);
        $contract->update(['contract_type' => 'DORM_BED', 'bed_id' => $bed->id, 'monthly_rent' => 1500000]);
        $term = $this->term($contract, 'FIXED', 'INTERNET');
        $payload = ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'];
        $id = $this->postJson($url, $payload)->assertOk()->json('data.0.invoices.0.id');
        $this->postJson('/api/invoices/'.$id.'/items', ['type' => 'OTHER', 'description' => 'Manual preserved', 'quantity' => 1, 'unit_price' => 5000])->assertCreated();
        $manualId = Invoice::findOrFail($id)->items()->where('source', 'MANUAL')->value('id');
        $confirmations = [['contract_id' => $contract->id, 'reason' => 'Thu riêng Internet theo phụ lục đã xác nhận',
            'extra_service_confirmed' => true, 'services' => [['term_id' => $term->id, 'quantity' => 1, 'amount' => 10000]]]];
        $route = '/api/billing-v2/rooms/'.$room->id.'/monthly-recalculations';
        $preview = $this->postJson($route.'/preview', [...$payload, 'confirmations' => $confirmations])
            ->assertOk()->assertJsonPath('data.status', 'PREVIEWED');
        $this->assertDatabaseHas('invoices', ['id' => $id, 'total_amount' => 1505000]);
        $token = $preview->json('data.token');
        $this->postJson($route.'/'.$token.'/confirm', ['confirmed' => true, 'reason' => 'Bổ sung thu riêng Internet đúng phụ lục'])
            ->assertOk()->assertJsonPath('data.status', 'UPDATED');
        $this->assertDatabaseHas('invoices', ['id' => $id, 'total_amount' => 1515000, 'status' => 'DRAFT']);
        $this->assertDatabaseHas('invoice_items', ['id' => $manualId, 'source' => 'MANUAL', 'amount' => 5000]);
        $this->assertDatabaseCount('invoice_items', 3);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payos_payment_requests', 0);
    }

    public function test_dormitory_selected_shared_meter_tiered_service_stays_blocked_without_approved_allocator(): void
    {
        [$contract, $url] = $this->fixture();
        $room = $contract->room;
        $room->update(['room_type' => 'DORMITORY']);
        $bed = $room->beds()->create(['bed_code' => 'C01', 'monthly_rent' => 1500000]);
        $contract->update(['contract_type' => 'DORM_BED', 'bed_id' => $bed->id, 'monthly_rent' => 1500000]);
        $term = $this->term($contract, 'TIERED', 'ELECTRICITY');
        $room->utilityMeters()->create(['service_id' => $term->service_id, 'is_active' => true]);
        $this->postJson($url, ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00',
            'confirmations' => [['contract_id' => $contract->id, 'reason' => 'Thu điện riêng đã có thỏa thuận', 'extra_service_confirmed' => true,
                'services' => [['term_id' => $term->id, 'quantity' => 1, 'amount' => 10000]]]]])
            ->assertOk()->assertJsonPath('data.0.status', 'BLOCKED');
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('utility_charge_allocations', 0);
    }

    public function test_owner_only_and_dormitory_without_bed_blocked(): void
    {
        [$c, $url] = $this->fixture();
        $c->room->update(['room_type' => 'DORMITORY']);
        $this->postJson($url, ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonPath('data.0.status', 'BLOCKED');
        $otherUser = User::factory()->create();
        $this->actingAs($otherUser, 'sanctum');
        config(['billing.v2_pilot_user_ids' => [$otherUser->id]]);
        $this->postJson($url, ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertNotFound();
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_generic_quantity_uses_agreed_price_and_preserves_decimal_quantity(): void
    {
        [$c, $url] = $this->fixture();
        $term = $this->term($c, 'PER_UNIT');
        $this->postJson($url, ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00', 'confirmations' => [['contract_id' => $c->id, 'reason' => 'Xác nhận số lượng sử dụng', 'services' => [['term_id' => $term->id, 'quantity' => 1.25, 'amount' => 12500]]]]])->assertOk()->assertJsonPath('data.0.status', 'CREATED');
        $this->assertDatabaseHas('invoice_items', ['type' => 'OTHER', 'quantity' => 1.25, 'unit_price' => 10000, 'amount' => 12500]);
    }

    public function test_monthly_invoice_without_meter_can_be_reviewed_and_explicitly_issued(): void
    {
        [$c, $url] = $this->fixture();
        $id = $this->postJson($url, ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->json('data.0.invoices.0.id');
        $this->postJson('/api/billing-v2/invoices/'.$id.'/issue')->assertOk();
        $this->postJson($url, ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonPath('data.0.status', 'BLOCKED');
        $this->assertDatabaseHas('invoices', ['id' => $id, 'status' => 'UNPAID', 'total_amount' => 3800000]);
    }

    public function test_foreign_owner_cannot_read_confirmation_options(): void
    {
        [$c] = $this->fixture();
        $url = '/api/billing-v2/boarding-houses/'.$c->room->boarding_house_id.'/monthly-options?billing_period=2026-10-01';
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.id', $c->id);
        $otherUser = User::factory()->create();
        $this->actingAs($otherUser, 'sanctum');
        config(['billing.v2_pilot_user_ids' => [$otherUser->id]]);
        $this->getJson($url)->assertNotFound();
    }

    public function test_missing_service_effective_date_does_not_silently_omit_charge(): void
    {
        [$c, $url] = $this->fixture();
        $term = $this->term($c, 'FIXED');
        $term->update(['effective_from' => null]);
        $this->postJson($url, ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonPath('data.0.status', 'BLOCKED');
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_inconsistent_paid_balance_is_blocked_without_overwrite(): void
    {
        [$c, $url] = $this->fixture();
        $id = $this->postJson($url, ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->json('data.0.invoices.0.id');
        Invoice::findOrFail($id)->update(['paid_amount' => 5000]);
        $this->postJson($url, ['billing_period' => '2026-10-01', 'usage_start_at' => '2026-10-01 00:00:00', 'usage_end_at' => '2026-11-01 00:00:00'])->assertOk()->assertJsonPath('data.0.status', 'BLOCKED');
        $this->assertDatabaseHas('invoices', ['id' => $id, 'paid_amount' => 5000, 'total_amount' => 3800000]);
        $this->assertDatabaseCount('invoices', 1);
    }
}
