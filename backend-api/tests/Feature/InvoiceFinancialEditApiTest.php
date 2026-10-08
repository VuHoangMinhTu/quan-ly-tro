<?php

namespace Tests\Feature;

use App\Models\BoardingHouse;
use App\Models\Invoice;
use App\Models\Landlord;
use App\Models\PayOSPaymentRequest;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvoiceFinancialEditApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_invoice_financial_data_can_be_edited(): void
    {
        [$user, $invoice] = $this->ownerAndInvoice('DRAFT');

        $this->updateInvoice($user, $invoice, ['discount_amount' => 100])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'discount_amount' => 100, 'total_amount' => 900, 'status' => 'DRAFT']);
    }

    public function test_unpaid_invoice_without_payments_can_have_financial_data_edited(): void
    {
        [$user, $invoice] = $this->ownerAndInvoice('UNPAID');

        $this->updateInvoice($user, $invoice, ['discount_amount' => 100])
            ->assertOk();

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'discount_amount' => 100, 'total_amount' => 900, 'status' => 'UNPAID']);
    }

    public function test_changing_an_unpaid_total_invalidates_the_existing_payos_qr(): void
    {
        [$user, $invoice] = $this->ownerAndInvoice('UNPAID');
        $request = PayOSPaymentRequest::create([
            'invoice_id' => $invoice->id,
            'order_code' => 9_000_000_200,
            'payment_link_id' => 'test-payos-link',
            'checkout_url' => 'https://pay.example/checkout',
            'qr_code' => '000201010212',
            'amount' => 1_000,
            'status' => 'PENDING',
        ]);
        // No real HTTP call is needed for this feature test. The local request must
        // still become STALE if cancellation is unavailable.
        config(['services.payos.client_id' => null]);

        $this->updateInvoice($user, $invoice, ['discount_amount' => 100])->assertOk();

        $this->assertSame('STALE', $request->fresh()->status);
        $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson("/api/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.payos_payment_request', null);
    }

    public function test_non_financial_note_change_keeps_the_existing_payos_qr(): void
    {
        [$user, $invoice] = $this->ownerAndInvoice('UNPAID');
        $request = PayOSPaymentRequest::create([
            'invoice_id' => $invoice->id,
            'order_code' => 9_000_000_201,
            'payment_link_id' => 'test-payos-link',
            'checkout_url' => 'https://pay.example/checkout',
            'qr_code' => '000201010212',
            'amount' => 1_000,
            'status' => 'PENDING',
        ]);

        $this->updateInvoice($user, $invoice, ['note' => 'Gia hạn đến ngày 05'])->assertOk();

        $this->assertSame('PENDING', $request->fresh()->status);
    }

    #[DataProvider('paidInvoiceStatuses')]
    public function test_financial_changes_are_blocked_after_a_payment_exists(string $status): void
    {
        [$user, $invoice] = $this->ownerAndInvoice($status, 500);

        $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson("/api/invoices/{$invoice->id}/items", [
                'type' => 'OTHER',
                'description' => 'Khoản thu mới',
                'quantity' => 1,
                'unit_price' => 100,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Hóa đơn đã có thanh toán nên không thể chỉnh sửa các khoản thu.');
    }

    public static function paidInvoiceStatuses(): array
    {
        return [
            'partially paid' => ['PARTIALLY_PAID'],
            'paid' => ['PAID'],
            'cancelled' => ['CANCELLED'],
        ];
    }

    private function ownerAndInvoice(string $status, int $paidAmount = 0): array
    {
        $user = User::factory()->create();
        $landlord = Landlord::create(['user_id' => $user->id, 'full_name' => $user->name, 'phone' => '0900000000']);
        $boardingHouse = BoardingHouse::create(['landlord_id' => $landlord->id, 'name' => 'House', 'address' => 'Address']);
        $room = Room::create(['boarding_house_id' => $boardingHouse->id, 'room_code' => 'P1', 'monthly_rent' => 1, 'status' => 'AVAILABLE']);
        $invoice = Invoice::create([
            'room_id' => $room->id,
            'invoice_code' => 'INV-'.Invoice::query()->count(),
            'billing_period' => '2026-10-01',
            'subtotal' => 1_000,
            'discount_amount' => 0,
            'total_amount' => 1_000,
            'paid_amount' => $paidAmount,
            'status' => $status,
        ]);

        return [$user, $invoice];
    }

    private function updateInvoice(User $user, Invoice $invoice, array $overrides = [])
    {
        return $this->withToken($user->createToken('test')->plainTextToken)
            ->putJson("/api/invoices/{$invoice->id}", array_merge([
                'invoice_code' => $invoice->invoice_code,
                'billing_period' => '2026-10-01',
                'discount_amount' => 0,
                'status' => $invoice->status,
                'note' => null,
            ], $overrides));
    }
}
