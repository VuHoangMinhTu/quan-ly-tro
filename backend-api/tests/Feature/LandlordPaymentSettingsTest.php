<?php

namespace Tests\Feature;

use App\Models\BoardingHouse;
use App\Models\Invoice;
use App\Models\Landlord;
use App\Models\LandlordPayOSConfig;
use App\Models\PayOSPaymentRequest;
use App\Models\Room;
use App\Models\User;
use App\Services\PayOSService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
use PayOS\Exceptions\WebhookException;
use PayOS\Models\Webhooks\WebhookData;
use Tests\TestCase;

class LandlordPaymentSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $user = User::factory()->create();
        Landlord::create(['user_id' => $user->id, 'full_name' => $user->name, 'phone' => '0900000000']);

        return $user;
    }

    public function test_owner_can_save_bank_profile_without_converting_account_number_and_other_owner_is_isolated(): void
    {
        $first = $this->owner();
        $second = $this->owner();
        $this->actingAs($first, 'sanctum')->putJson('/api/landlord/payment-profile', [
            'bank_name' => ' Vietcombank ', 'bank_account_name' => ' Minh Tú ', 'bank_account_number' => '0012345',
        ])->assertOk()->assertJsonPath('data.bank_account_number', '0012345');
        $this->actingAs($second, 'sanctum')->getJson('/api/landlord/payment-profile')->assertOk()->assertJsonPath('data.bank_account_number', null);
        $this->assertDatabaseHas('landlord_payment_profiles', ['landlord_id' => $first->landlord->id, 'bank_account_number' => '0012345']);
    }

    public function test_manager_cannot_change_owner_payment_settings(): void
    {
        $manager = User::factory()->create();
        $this->actingAs($manager, 'sanctum')->putJson('/api/landlord/payment-profile', ['bank_name' => 'Wrong'])->assertForbidden();
        $this->actingAs($manager, 'sanctum')->putJson('/api/landlord/payos-config', ['client_id' => 'x', 'api_key' => 'y', 'checksum_key' => 'z'])->assertForbidden();
    }

    public function test_payos_secrets_are_encrypted_and_never_returned(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'sanctum')->putJson('/api/landlord/payos-config', [
            'client_id' => 'merchant-1234', 'api_key' => 'secret-api', 'checksum_key' => 'secret-checksum',
        ])->assertOk();
        $raw = DB::table('landlord_payos_configs')->first();
        $this->assertNotSame('secret-api', $raw->api_key);
        $this->assertNotSame('secret-checksum', $raw->checksum_key);
        $this->assertSame('secret-api', LandlordPayOSConfig::firstOrFail()->api_key);
        $response = $this->getJson('/api/landlord/payos-config')->assertOk()->assertJsonPath('data.configured', true);
        $this->assertStringNotContainsString('secret-api', $response->getContent());
        $this->assertStringNotContainsString('secret-checksum', $response->getContent());
        $this->assertNotNull($response->json('data.webhook_url'));
        $this->deleteJson('/api/landlord/payos-config')->assertOk();
        $this->getJson('/api/landlord/payos-config')->assertJsonPath('data.is_active', false);
        $this->putJson('/api/landlord/payos-config', ['is_active' => true])->assertOk();
        $this->getJson('/api/landlord/payos-config')->assertJsonPath('data.is_active', true);
    }

    public function test_only_explicit_fallback_landlord_can_use_global_payos(): void
    {
        $first = $this->owner();
        $second = $this->owner();
        config(['services.payos.global_fallback_landlord_id' => $first->landlord->id,
            'services.payos.client_id' => 'test-client', 'services.payos.api_key' => 'test-api', 'services.payos.checksum_key' => 'test-checksum']);
        $payos = app(PayOSService::class);
        $this->assertTrue($payos->availableFor($first->landlord));
        $this->assertFalse($payos->availableFor($second->landlord));

        $house = BoardingHouse::create(['landlord_id' => $second->landlord->id, 'name' => 'House', 'address' => 'Address']);
        $room = Room::create(['boarding_house_id' => $house->id, 'room_code' => '101', 'status' => 'AVAILABLE']);
        $invoice = Invoice::create(['room_id' => $room->id, 'invoice_code' => 'INV-101', 'billing_period' => '2026-10-01', 'subtotal' => 100000, 'discount_amount' => 0, 'total_amount' => 100000, 'paid_amount' => 0, 'status' => 'UNPAID']);
        $this->expectException(ValidationException::class);
        $payos->createOrReuse($invoice);
    }

    public function test_webhook_uuid_selects_the_merchant_before_signature_verification(): void
    {
        $first = $this->owner();
        $second = $this->owner();
        $a = $first->landlord->payosConfig()->create(['client_id' => 'a', 'api_key' => 'a-secret', 'checksum_key' => 'a-check', 'webhook_uuid' => '68b301c1-c6af-4435-9101-036bc7a84e6a', 'is_active' => true]);
        $b = $second->landlord->payosConfig()->create(['client_id' => 'b', 'api_key' => 'b-secret', 'checksum_key' => 'b-check', 'webhook_uuid' => '68b301c1-c6af-4435-9101-036bc7a84e6b', 'is_active' => true]);
        $service = Mockery::mock(PayOSService::class)->makePartial();
        $service->shouldReceive('verifyWebhook')->once()->withArgs(fn ($payload, $config) => $config->is($b))
            ->andThrow(new WebhookException('Invalid signature'));
        $this->app->instance(PayOSService::class, $service);

        $this->postJson('/api/webhooks/payos/'.$b->webhook_uuid, ['code' => '00'])->assertStatus(400);
        $this->postJson('/api/webhooks/payos/unknown', ['code' => '00'])->assertNotFound();
        $this->assertDatabaseCount('payments', 0);
        $this->assertNotSame($a->webhook_uuid, $b->webhook_uuid);
    }

    public function test_a_verified_webhook_cannot_apply_to_another_merchant_order(): void
    {
        $first = $this->owner();
        $second = $this->owner();
        $house = BoardingHouse::create(['landlord_id' => $first->landlord->id, 'name' => 'House', 'address' => 'Address']);
        $room = Room::create(['boarding_house_id' => $house->id, 'room_code' => '101', 'status' => 'AVAILABLE']);
        $invoice = Invoice::create(['room_id' => $room->id, 'invoice_code' => 'INV-101', 'billing_period' => '2026-10-01', 'subtotal' => 100000, 'discount_amount' => 0, 'total_amount' => 100000, 'paid_amount' => 0, 'status' => 'UNPAID']);
        $paymentRequest = PayOSPaymentRequest::create(['invoice_id' => $invoice->id, 'order_code' => 9_000_000_001, 'amount' => 100000, 'status' => 'PENDING', 'payment_link_id' => 'link-1']);
        $data = new WebhookData($paymentRequest->order_code, 100000, 'Invoice', '123', 'FOREIGN-REF', '2026-10-23T10:00:00+07:00', 'VND', 'link-1', '00', 'success');

        $this->assertNull(app(PayOSService::class)->processVerifiedWebhook($data, $second->landlord->id));
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('PENDING', $paymentRequest->fresh()->status);
    }
}
