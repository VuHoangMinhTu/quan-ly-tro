<?php

namespace Tests\Feature;

use App\Exceptions\InvoiceFinancialEditException;
use App\Models\BoardingHouse;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Landlord;
use App\Models\Room;
use App\Models\RoomTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingService;
use App\Services\PayOSService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PayOS\Models\V2\PaymentRequests\CreatePaymentLinkResponse;
use PayOS\Models\V2\PaymentRequests\PaymentLinkStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BillingVatApiTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('billingMethods')]
    public function test_each_billing_method_generates_only_the_base_charge_even_with_a_configured_vat_rate(string $method, string $amount): void
    {
        [$user, $invoice, $service] = $this->fixture($method);
        $this->actingAs($user, 'sanctum')->postJson("/api/invoices/{$invoice->id}/generate")->assertOk()->assertJsonPath('data', null);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'ELECTRICITY', 'amount' => $amount, 'source' => 'AUTO']);
        $this->assertDatabaseMissing('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'SERVICE_TAX']);
        $this->assertDatabaseCount('invoice_items', 1);
        $this->assertSame($amount, $invoice->fresh()->subtotal);
        $this->assertSame($amount, $invoice->fresh()->total_amount);
        $this->assertSame('8.00', $service->fresh()->vat_rate);
    }

    public static function billingMethods(): array
    {
        return ['fixed' => ['FIXED', '284000.00'], 'per unit' => ['PER_UNIT', '284000.00'], 'per person' => ['PER_PERSON', '284000.00'], 'tiered' => ['TIERED', '234000.00']];
    }

    public function test_zero_rate_does_not_create_tax_and_other_service_is_not_changed(): void
    {
        [$user, $invoice, $service] = $this->fixture('FIXED');
        $service->update(['vat_rate' => 0]);
        $water = $invoice->room->boardingHouse->services()->create(['name' => 'Nước', 'type' => 'WATER', 'billing_method' => 'FIXED', 'base_price' => 20000]);
        $invoice->room->services()->attach($water->id, ['is_active' => true]);
        $this->actingAs($user, 'sanctum')->postJson("/api/invoices/{$invoice->id}/generate")->assertOk();
        $this->assertDatabaseMissing('invoice_items', ['type' => 'SERVICE_TAX']);
        $this->assertSame('304000.00', $invoice->fresh()->total_amount);
        $this->assertSame('0.00', $water->fresh()->vat_rate);
    }

    public function test_explicit_regeneration_removes_legacy_auto_tax_without_duplicates_or_losing_manual_items(): void
    {
        [$user, $invoice, $service] = $this->fixture('FIXED');
        $manual = $invoice->items()->create(['type' => 'OTHER', 'description' => 'Thủ công', 'quantity' => 1, 'unit_price' => 10000, 'amount' => 10000, 'source' => 'MANUAL']);
        $this->actingAs($user, 'sanctum')->postJson("/api/invoices/{$invoice->id}/generate")->assertOk();
        $this->addHistoricalTax($invoice);
        $service->update(['vat_rate' => 10]);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'description' => 'VAT tiền điện (8%)', 'tax_rate' => 8]);
        $this->postJson("/api/invoices/{$invoice->id}/generate")->assertOk();
        $this->postJson("/api/invoices/{$invoice->id}/generate")->assertOk();
        $this->assertModelExists($manual);
        $this->assertDatabaseCount('invoice_items', 2);
        $this->assertDatabaseMissing('invoice_items', ['type' => 'SERVICE_TAX']);
        $this->assertSame('294000.00', $invoice->fresh()->total_amount);
    }

    public function test_small_fractional_base_charge_is_preserved_without_tax_or_float_artifacts(): void
    {
        [$user, $invoice, $service] = $this->fixture('FIXED');
        $service->update(['base_price' => '6.25']);
        $this->actingAs($user, 'sanctum')->postJson("/api/invoices/{$invoice->id}/generate")->assertOk();
        $this->assertSame('6.25', $invoice->fresh()->total_amount);
        $this->assertDatabaseMissing('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'SERVICE_TAX']);
    }

    public function test_paid_invoice_cannot_regenerate_and_keeps_its_tax_snapshot(): void
    {
        [$user, $invoice, $service] = $this->fixture('FIXED');
        $this->actingAs($user, 'sanctum')->postJson("/api/invoices/{$invoice->id}/generate")->assertOk();
        $this->addHistoricalTax($invoice);
        $invoice->refresh()->update(['status' => 'PAID', 'paid_amount' => 306720]);
        $service->update(['vat_rate' => 10]);
        $this->postJson("/api/invoices/{$invoice->id}/generate")->assertUnprocessable();
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'tax_rate' => 8, 'amount' => 22720]);
        $this->assertSame('306720.00', $invoice->fresh()->total_amount);
    }

    public function test_real_payos_flow_uses_the_remaining_amount_without_tax_for_new_charges(): void
    {
        [$user, $invoice] = $this->fixture('PER_UNIT');
        config(['services.payos.global_fallback_landlord_id' => $user->landlord->id,
            'services.payos.client_id' => 'test-client', 'services.payos.api_key' => 'test-api', 'services.payos.checksum_key' => 'test-checksum']);
        $this->actingAs($user, 'sanctum')->postJson("/api/invoices/{$invoice->id}/generate")->assertOk();
        $this->postJson("/api/invoices/{$invoice->id}/payments", ['amount' => 10000, 'payment_method' => 'CASH', 'paid_at' => '2026-10-15 10:00:00'])->assertCreated();
        $service = Mockery::mock(PayOSService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('createPaymentLink')->once()->withArgs(function ($current, $request): bool {
            return $current->total_amount === '284000.00' && $request->amount === '274000.00';
        })->andReturnUsing(fn ($current, $request) => new CreatePaymentLinkResponse('970422', '123456789', 'Test', 274000, 'Test', $request->order_code, 'VND', 'vat-test', PaymentLinkStatus::PENDING, 'https://pay.example/vat', '000201010212'));
        $this->app->instance(PayOSService::class, $service);
        $this->postJson("/api/invoices/{$invoice->id}/payos")->assertOk()->assertJsonPath('data.payment_request.amount', 274000);
        $this->assertDatabaseHas('payos_payment_requests', ['invoice_id' => $invoice->id, 'amount' => 274000, 'payment_link_id' => 'vat-test']);
    }

    public function test_stale_loaded_invoice_cannot_regenerate_after_persisted_invoice_is_paid(): void
    {
        [$user, $stale, $service] = $this->fixture('FIXED');
        $this->actingAs($user, 'sanctum')->postJson("/api/invoices/{$stale->id}/generate")->assertOk();
        $this->addHistoricalTax($stale);
        $stale->fresh()->update(['status' => 'PAID', 'paid_amount' => 306720]);
        $service->update(['vat_rate' => 10]);
        try {
            app(BillingService::class)->generateForInvoice($stale);
            $this->fail('A stale editable model must not bypass the persisted payment lock.');
        } catch (InvoiceFinancialEditException $exception) {
            $this->assertSame('Hóa đơn đã có thanh toán nên không thể chỉnh sửa các khoản thu.', $exception->getMessage());
        }
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $stale->id, 'tax_rate' => 8, 'amount' => 22720]);
        $this->assertSame('306720.00', $stale->fresh()->total_amount);
    }

    public function test_new_invoice_keeps_rent_water_services_manual_charges_and_discount_without_vat(): void
    {
        [$user, $invoice] = $this->fixture('PER_UNIT');
        $contract = Contract::factory()->create(['room_id' => $invoice->room_id, 'tenant_id' => Tenant::create(['landlord_id' => $user->landlord->id, 'full_name' => 'Người thuê'])->id,
            'start_date' => '2026-10-01', 'end_date' => '2027-09-30', 'monthly_rent' => 3000000]);
        $invoice->update(['contract_id' => $contract->id]);
        $house = $invoice->room->boardingHouse;
        $water = $house->services()->create(['name' => 'Nước', 'type' => 'WATER', 'billing_method' => 'FIXED', 'base_price' => 20000, 'vat_rate' => 8]);
        $internet = $house->services()->create(['name' => 'Internet', 'type' => 'INTERNET', 'billing_method' => 'FIXED', 'base_price' => 100000, 'vat_rate' => 10]);
        $invoice->room->services()->attach([$water->id => ['is_active' => true], $internet->id => ['is_active' => true]]);
        $manual = $invoice->items()->create(['type' => 'OTHER', 'description' => 'Khoản thu khác', 'quantity' => 1, 'unit_price' => 50000, 'amount' => 50000, 'source' => 'MANUAL']);

        $this->actingAs($user, 'sanctum')->postJson("/api/invoices/{$invoice->id}/generate")->assertOk();
        $invoice->refresh()->update(['discount_amount' => 10000]);
        $this->postJson("/api/invoices/{$invoice->id}/generate")->assertOk();

        $this->assertSame('3454000.00', $invoice->fresh()->subtotal);
        $this->assertSame('3444000.00', $invoice->fresh()->total_amount);
        $this->assertSame(5, $invoice->items()->count());
        $this->assertModelExists($manual);
        $this->assertDatabaseMissing('invoice_items', ['type' => 'SERVICE_TAX']);
    }

    public function test_reading_an_issued_legacy_invoice_preserves_its_tax_and_totals(): void
    {
        [$user, $invoice] = $this->fixture('FIXED');
        $this->actingAs($user, 'sanctum')->postJson("/api/invoices/{$invoice->id}/generate")->assertOk();
        $this->addHistoricalTax($invoice);

        $this->getJson("/api/invoices/{$invoice->id}")->assertOk()->assertJsonPath('data.total_amount', '306720.00');

        $this->assertSame('306720.00', $invoice->fresh()->total_amount);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'SERVICE_TAX', 'amount' => 22720]);
    }

    public function test_agreed_contract_service_rate_does_not_add_vat_to_new_invoice(): void
    {
        [$user, $invoice, $service] = $this->fixture('FIXED');
        $contract = Contract::factory()->create(['room_id' => $invoice->room_id, 'monthly_rent' => 3000000]);
        $term = $contract->agreedServices()->create(['service_id' => $service->id, 'service_name' => 'Điện', 'service_type' => 'ELECTRICITY',
            'billing_method' => 'FIXED', 'base_price' => 284000, 'vat_rate' => 8, 'is_active' => true, 'effective_from' => '2026-10-01']);
        $contract->forceFill(['service_terms_version' => 1, 'signing_snapshot' => ['services' => [['id' => $term->id]]]])->save();
        $invoice->update(['contract_id' => $contract->id]);

        $this->actingAs($user, 'sanctum')->postJson("/api/invoices/{$invoice->id}/generate")->assertOk();

        $this->assertSame('3284000.00', $invoice->fresh()->total_amount);
        $this->assertDatabaseMissing('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'SERVICE_TAX']);
        $this->assertSame('8.00', $term->fresh()->vat_rate);
    }

    private function addHistoricalTax(Invoice $invoice): void
    {
        $invoice->items()->create(['type' => 'SERVICE_TAX', 'description' => 'VAT tiền điện (8%)', 'quantity' => 1, 'unit_price' => 22720, 'amount' => 22720, 'tax_rate' => 8, 'source' => 'AUTO']);
        $invoice->recalculateTotals();
    }

    private function fixture(string $method): array
    {
        $user = User::factory()->create();
        $landlord = Landlord::create(['user_id' => $user->id, 'full_name' => $user->name, 'phone' => '0900000000']);
        $house = BoardingHouse::create(['landlord_id' => $landlord->id, 'name' => 'BeeHouse', 'address' => 'Địa chỉ']);
        $room = Room::create(['boarding_house_id' => $house->id, 'room_code' => '101', 'monthly_rent' => null, 'status' => 'AVAILABLE']);
        $service = $house->services()->create(['name' => 'Điện', 'type' => 'ELECTRICITY', 'billing_method' => $method, 'base_price' => $method === 'PER_UNIT' ? 4000 : 284000, 'vat_rate' => 8, 'is_active' => true]);
        $room->services()->attach($service->id, ['is_active' => true]);
        if (in_array($method, ['PER_UNIT', 'TIERED'], true)) {
            $meter = $room->utilityMeters()->create(['service_id' => $service->id, 'initial_reading' => 0, 'is_active' => true]);
            $meter->readings()->create(['reading_date' => '2026-10-31', 'reading_value' => 71]);
        }
        if ($method === 'TIERED') {
            $service->priceTiers()->createMany([['from_quantity' => 0, 'to_quantity' => 50, 'unit_price' => 3000, 'tier_order' => 1], ['from_quantity' => 50, 'to_quantity' => null, 'unit_price' => 4000, 'tier_order' => 2]]);
        }
        if ($method === 'PER_PERSON') {
            $tenant = Tenant::create(['landlord_id' => $landlord->id, 'full_name' => 'Người thuê']);
            RoomTenant::create(['room_id' => $room->id, 'tenant_id' => $tenant->id, 'status' => 'ACTIVE', 'move_in_date' => '2026-10-01']);
        }
        $invoice = $room->invoices()->create(['invoice_code' => 'VAT-1', 'billing_period' => '2026-10-01', 'status' => 'UNPAID', 'subtotal' => 0, 'total_amount' => 0, 'paid_amount' => 0, 'discount_amount' => 0]);

        return [$user, $invoice, $service];
    }
}
