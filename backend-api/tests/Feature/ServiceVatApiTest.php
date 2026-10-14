<?php

namespace Tests\Feature;

use App\Models\BoardingHouse;
use App\Models\Landlord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServiceVatApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_defaults_to_zero_vat_and_allows_setting_and_clearing_rate(): void
    {
        [$user, $house] = $this->owner();
        $this->actingAs($user, 'sanctum')->postJson("/api/boarding-houses/{$house->id}/services", $this->payload())->assertCreated()->assertJsonPath('data', null);
        $service = $house->services()->sole();
        $this->assertSame('0.00', $service->vat_rate);

        $this->putJson("/api/services/{$service->id}", [...$this->payload(), 'vat_rate' => 8])->assertOk();
        $this->assertSame('8.00', $service->fresh()->vat_rate);
        $this->putJson("/api/services/{$service->id}", $this->payload())->assertOk();
        $this->assertSame('8.00', $service->fresh()->vat_rate);
        $this->getJson("/api/services/{$service->id}")->assertOk()->assertJsonPath('data.vat_rate', '8.00');
        $this->putJson("/api/services/{$service->id}", [...$this->payload(), 'vat_rate' => ''])->assertOk();
        $this->assertSame('0.00', $service->fresh()->vat_rate);
    }

    #[DataProvider('invalidRates')]
    public function test_invalid_vat_returns_422_without_creating_service(mixed $rate): void
    {
        [$user, $house] = $this->owner();
        $this->actingAs($user, 'sanctum')->postJson("/api/boarding-houses/{$house->id}/services", [...$this->payload(), 'vat_rate' => $rate])
            ->assertUnprocessable()->assertJsonValidationErrors('vat_rate');
        $this->assertDatabaseCount('services', 0);
    }

    public static function invalidRates(): array
    {
        return ['negative' => [-1], 'above maximum' => [101], 'excess precision' => ['8.555'], 'text' => ['abc']];
    }

    public function test_other_owner_cannot_change_a_services_vat_rate(): void
    {
        [$owner, $house] = $this->owner();
        [$other] = $this->owner();
        $service = $house->services()->create([...$this->payload(), 'vat_rate' => 8]);
        $this->actingAs($other, 'sanctum')->putJson("/api/services/{$service->id}", [...$this->payload(), 'vat_rate' => 10])->assertNotFound();
        $this->assertSame('8.00', $service->fresh()->vat_rate);
    }

    private function payload(): array
    {
        return ['name' => 'Điện', 'type' => 'ELECTRICITY', 'billing_method' => 'FIXED', 'base_price' => 284000, 'unit' => null];
    }

    private function owner(): array
    {
        $user = User::factory()->create();
        $landlord = Landlord::create(['user_id' => $user->id, 'full_name' => $user->name, 'phone' => '0900000000']);

        return [$user, BoardingHouse::create(['landlord_id' => $landlord->id, 'name' => 'BeeHouse', 'address' => 'Địa chỉ'])];
    }
}
