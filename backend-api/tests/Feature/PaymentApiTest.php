<?php

namespace Tests\Feature;

use App\Models\BoardingHouse;
use App\Models\Invoice;
use App\Models\Landlord;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_receives_200_and_an_empty_list_for_invoice_without_payments(): void
    {
        [$user, $invoice] = $this->ownerAndInvoice();

        $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson("/api/invoices/{$invoice->id}/payments")
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Lấy danh sách thanh toán thành công.',
                'data' => [],
            ]);
    }

    #[DataProvider('paymentMethods')]
    public function test_owner_receives_200_and_existing_payments(string $method, string $source): void
    {
        [$user, $invoice] = $this->ownerAndInvoice();
        $payment = $invoice->payments()->create([
            'amount' => 250000,
            'payment_method' => $method,
            'payment_source' => $source,
            'external_reference' => $source === 'PAYOS_WEBHOOK' ? 'payos-test-reference' : null,
            'paid_at' => '2026-10-09 12:00:00',
        ]);

        $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson("/api/invoices/{$invoice->id}/payments")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Lấy danh sách thanh toán thành công.')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $payment->id)
            ->assertJsonPath('data.0.invoice_id', $invoice->id)
            ->assertJsonPath('data.0.amount', '250000.00')
            ->assertJsonPath('data.0.payment_method', $method)
            ->assertJsonPath('data.0.payment_source', $source);
    }

    public static function paymentMethods(): array
    {
        return [
            'cash' => ['CASH', 'MANUAL'],
            'payos' => ['PAYOS', 'PAYOS_WEBHOOK'],
        ];
    }

    public function test_returns_404_for_another_landlords_invoice(): void
    {
        [, $foreignInvoice] = $this->ownerAndInvoice();
        [$user] = $this->ownerAndInvoice();

        $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson("/api/invoices/{$foreignInvoice->id}/payments")
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_returns_401_without_authentication(): void
    {
        [, $invoice] = $this->ownerAndInvoice();

        $this->getJson("/api/invoices/{$invoice->id}/payments")
            ->assertUnauthorized();
    }

    private function ownerAndInvoice(): array
    {
        $user = User::factory()->create();
        $landlord = Landlord::create(['user_id' => $user->id, 'full_name' => $user->name, 'phone' => '0901234567']);
        $house = BoardingHouse::create(['landlord_id' => $landlord->id, 'name' => 'Nhà trọ', 'address' => 'Địa chỉ']);
        $room = Room::create(['boarding_house_id' => $house->id, 'room_code' => 'P001', 'monthly_rent' => 1000000, 'status' => 'AVAILABLE']);
        $invoice = Invoice::create([
            'room_id' => $room->id,
            'invoice_code' => 'INV-'.$room->id,
            'billing_period' => '2026-10-01',
            'subtotal' => 1000000,
            'total_amount' => 1000000,
            'status' => 'UNPAID',
        ]);

        return [$user, $invoice];
    }

    /**
     * A basic feature test example.
     */
    public function test_example(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
