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

    public function test_key_survives_replay_edit_and_removal_and_conflicting_payload_is_rejected(): void
    {
        [$owner, $invoice] = $this->ownerAndInvoice();
        $this->withToken($owner->createToken('test')->plainTextToken);
        $url = "/api/invoices/{$invoice->id}/payments";
        $payload = ['amount' => 100000, 'payment_method' => 'CASH', 'paid_at' => '2026-10-10 08:00:00'];
        $keyed = [...$payload, 'request_key' => 'e98ce958-a01d-4d67-9abd-7b1622167a01'];
        $this->postJson($url, $keyed)->assertCreated();
        $this->postJson($url, [...$keyed, 'amount' => '100000.00'])->assertOk()->assertJsonPath('data.replayed', true);
        $this->postJson($url, [...$keyed, 'amount' => 200000])->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $payment = $invoice->payments()->sole();
        $this->putJson("/api/payments/{$payment->id}", [...$payload, 'amount' => 150000])->assertOk();
        $this->postJson($url, $keyed)->assertOk();
        $this->assertSame('150000.00', $invoice->payments()->sole()->amount);
        $this->deleteJson("/api/payments/{$payment->id}")->assertOk();
        $this->postJson($url, $keyed)->assertOk();
        $this->assertDatabaseCount('payments', 0);
        $this->postJson($url, [...$keyed, 'request_key' => 'e98ce958-a01d-4d67-9abd-7b1622167a02'])->assertCreated();
        $this->postJson($url, $payload)->assertCreated();
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('manual_payment_requests', 2);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('payos_payment_requests', 0);
    }

    public function test_keyed_payment_is_scoped_to_owned_invoice_and_failed_validation_does_not_consume_key(): void
    {
        [$owner, $invoice] = $this->ownerAndInvoice();
        [$other, $foreign] = $this->ownerAndInvoice();
        $payload = ['amount' => 100000, 'payment_method' => 'CASH', 'paid_at' => '2026-10-10 08:00:00', 'request_key' => 'e98ce958-a01d-4d67-9abd-7b1622167a01'];
        $this->withToken($owner->createToken('test')->plainTextToken)
            ->postJson("/api/invoices/{$foreign->id}/payments", $payload)->assertNotFound();
        $this->postJson("/api/invoices/{$invoice->id}/payments", [...$payload, 'amount' => 2000000])->assertUnprocessable();
        $this->assertDatabaseCount('manual_payment_requests', 0);
        $this->postJson("/api/invoices/{$invoice->id}/payments", $payload)->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->withToken($other->createToken('test')->plainTextToken)
            ->postJson("/api/invoices/{$foreign->id}/payments", $payload)->assertCreated();
        $this->assertDatabaseCount('manual_payment_requests', 2);
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
