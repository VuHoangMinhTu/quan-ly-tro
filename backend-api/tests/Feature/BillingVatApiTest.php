<?php

namespace Tests\Feature;

use App\Exceptions\InvoiceFinancialEditException;
use App\Models\BoardingHouse;
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
    public function test_each_billing_method_generates_a_separate_snapshotted_vat_item(string $method, string $amount, string $tax): void
    {
        [$user, $invoice, $service] = $this->fixture($method);
        $this->actingAs($user, 'sanctum')->postJson("/api/invoices/{$invoice->id}/generate")->assertOk()->assertJsonPath('data', null);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'ELECTRICITY', 'amount' => $amount, 'source' => 'AUTO']);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'SERVICE_TAX', 'description' => 'VAT tiền điện (8%)', 'quantity' => 1, 'unit_price' => $tax, 'amount' => $tax, 'tax_rate' => 8, 'source' => 'AUTO']);
        $this->assertDatabaseCount('invoice_items', 2);
    }

    public static function billingMethods(): array
    {
        return ['fixed' => ['FIXED', '284000.00', '22720.00'], 'per unit' => ['PER_UNIT', '284000.00', '22720.00'], 'per person' => ['PER_PERSON', '284000.00', '22720.00'], 'tiered' => ['TIERED', '234000.00', '18720.00']];
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

    public function test_regeneration_recalculates_vat_without_duplicates_or_losing_manual_items(): void
    {
        [$user, $invoice, $service] = $this->fixture('FIXED');
        $manual = $invoice->items()->create(['type' => 'OTHER', 'description' => 'Thủ công', 'quantity' => 1, 'unit_price' => 10000, 'amount' => 10000, 'source' => 'MANUAL']);
        $this->actingAs($user, 'sanctum')->postJson("/api/invoices/{$invoice->id}/generate")->assertOk();
        $service->update(['vat_rate' => 10]);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'description' => 'VAT tiền điện (8%)', 'tax_rate' => 8]);
        $this->postJson("/api/invoices/{$invoice->id}/generate")->assertOk();
        $this->postJson("/api/invoices/{$invoice->id}/generate")->assertOk();
        $this->assertModelExists($manual);
        $this->assertDatabaseCount('invoice_items', 3);
        $this->assertDatabaseHas('invoice_items', ['type' => 'SERVICE_TAX', 'amount' => 28400, 'tax_rate' => 10, 'description' => 'VAT tiền điện (10%)']);
        $this->assertSame('322400.00', $invoice->fresh()->total_amount);
    }

    public function test_tax_rounds_half_up_to_one_dong_without_float_artifacts(): void
    {
        [$user, $invoice, $service] = $this->fixture('FIXED');
        $service->update(['base_price' => '6.25']);
        $this->actingAs($user, 'sanctum')->postJson("/api/invoices/{$invoice->id}/generate")->assertOk();
        $this->assertSame('1.00', $invoice->items()->where('type', 'SERVICE_TAX')->sole()->amount);
    }

    public function test_paid_invoice_cannot_regenerate_and_keeps_its_tax_snapshot(): void
    {
        [$user, $invoice, $service] = $this->fixture('FIXED');
        $this->actingAs($user, 'sanctum')->postJson("/api/invoices/{$invoice->id}/generate")->assertOk();
        $invoice->refresh()->update(['status' => 'PAID', 'paid_amount' => 306720]);
        $service->update(['vat_rate' => 10]);
        $this->postJson("/api/invoices/{$invoice->id}/generate")->assertUnprocessable();
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'tax_rate' => 8, 'amount' => 22720]);
        $this->assertSame('306720.00', $invoice->fresh()->total_amount);
    }

    public function test_real_payos_flow_uses_the_remaining_amount_including_tax(): void
    {
        [$user, $invoice] = $this->fixture('PER_UNIT');
        config(['services.payos.global_fallback_landlord_id' => $user->landlord->id,
            'services.payos.client_id' => 'test-client', 'services.payos.api_key' => 'test-api', 'services.payos.checksum_key' => 'test-checksum']);
        $this->actingAs($user, 'sanctum')->postJson("/api/invoices/{$invoice->id}/generate")->assertOk();
        $this->postJson("/api/invoices/{$invoice->id}/payments", ['amount' => 10000, 'payment_method' => 'CASH', 'paid_at' => '2026-10-15 10:00:00'])->assertCreated();
        $service = Mockery::mock(PayOSService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('createPaymentLink')->once()->withArgs(function ($current, $request): bool {
            return $current->total_amount === '306720.00' && $request->amount === '296720.00';
        })->andReturnUsing(fn ($current, $request) => new CreatePaymentLinkResponse('970422', '123456789', 'Test', 296720, 'Test', $request->order_code, 'VND', 'vat-test', PaymentLinkStatus::PENDING, 'https://pay.example/vat', '000201010212'));
        $this->app->instance(PayOSService::class, $service);
        $this->postJson("/api/invoices/{$invoice->id}/payos")->assertOk()->assertJsonPath('data.payment_request.amount', 296720);
        $this->assertDatabaseHas('payos_payment_requests', ['invoice_id' => $invoice->id, 'amount' => 296720, 'payment_link_id' => 'vat-test']);
    }

    public function test_stale_loaded_invoice_cannot_regenerate_after_persisted_invoice_is_paid(): void
    {
        [$user, $stale, $service] = $this->fixture('FIXED');
        $this->actingAs($user, 'sanctum')->postJson("/api/invoices/{$stale->id}/generate")->assertOk();
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
