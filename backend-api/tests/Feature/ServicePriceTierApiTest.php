<?php

namespace Tests\Feature;

use App\Models\BoardingHouse;
use App\Models\Landlord;
use App\Models\Service;
use App\Models\ServicePriceTier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicePriceTierApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_landlord_can_create_tier_for_own_tiered_service(): void
    {
        $landlord = $this->createLandlord();
        $service = $this->createService($this->createBoardingHouse($landlord));

        $this->withBearerToken($landlord)->postJson("/api/services/{$service->id}/price-tiers", $this->tierPayload())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);

        $this->assertDatabaseHas('service_price_tiers', ['service_id' => $service->id, 'tier_order' => 1]);
    }

    public function test_cannot_create_tier_for_non_tiered_service_or_another_landlord(): void
    {
        $landlord = $this->createLandlord();
        $nonTiered = $this->createService($this->createBoardingHouse($landlord), ['billing_method' => 'PER_UNIT', 'base_price' => 3800]);
        $otherService = $this->createService($this->createBoardingHouse($this->createLandlord()));

        $this->withBearerToken($landlord)->postJson("/api/services/{$nonTiered->id}/price-tiers", $this->tierPayload())
            ->assertStatus(422)->assertJsonValidationErrors('service_id');
        $this->withBearerToken($landlord)->postJson("/api/services/{$otherService->id}/price-tiers", $this->tierPayload())
            ->assertNotFound();
    }

    public function test_landlord_cannot_update_or_delete_another_landlords_tier(): void
    {
        $otherTier = $this->createTier($this->createService($this->createBoardingHouse($this->createLandlord())));
        $landlord = $this->createLandlord();

        $this->withBearerToken($landlord)->putJson("/api/service-price-tiers/{$otherTier->id}", $this->tierPayload())
            ->assertNotFound();
        $this->withBearerToken($landlord)->deleteJson("/api/service-price-tiers/{$otherTier->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('service_price_tiers', ['id' => $otherTier->id]);
    }

    public function test_tier_order_unique_and_overlapping_or_gapped_ranges_are_rejected(): void
    {
        $landlord = $this->createLandlord();
        $service = $this->createService($this->createBoardingHouse($landlord));
        $this->createTier($service, ['from_quantity' => 0, 'to_quantity' => 50, 'tier_order' => 1]);

        $this->withBearerToken($landlord)->postJson("/api/services/{$service->id}/price-tiers", $this->tierPayload([
            'from_quantity' => 40, 'to_quantity' => 100, 'tier_order' => 2,
        ]))->assertStatus(422)->assertJsonValidationErrors('from_quantity');

        $this->withBearerToken($landlord)->postJson("/api/services/{$service->id}/price-tiers", $this->tierPayload([
            'from_quantity' => 50, 'to_quantity' => 100, 'tier_order' => 1,
        ]))->assertStatus(422)->assertJsonValidationErrors('tier_order');

        $this->withBearerToken($landlord)->postJson("/api/services/{$service->id}/price-tiers", $this->tierPayload([
            'from_quantity' => 60, 'to_quantity' => 100, 'tier_order' => 2,
        ]))->assertStatus(422)->assertJsonValidationErrors('from_quantity');
    }

    public function test_last_tier_can_be_open_ended_but_no_tier_can_follow_it(): void
    {
        $landlord = $this->createLandlord();
        $service = $this->createService($this->createBoardingHouse($landlord));
        $this->createTier($service, ['from_quantity' => 0, 'to_quantity' => 50, 'tier_order' => 1]);

        $this->withBearerToken($landlord)->postJson("/api/services/{$service->id}/price-tiers", $this->tierPayload([
            'from_quantity' => 50, 'to_quantity' => null, 'tier_order' => 2,
        ]))->assertCreated();

        $this->withBearerToken($landlord)->postJson("/api/services/{$service->id}/price-tiers", $this->tierPayload([
            'from_quantity' => 100, 'to_quantity' => null, 'tier_order' => 3,
        ]))->assertStatus(422)->assertJsonValidationErrors('tier_order');
    }

    public function test_invalid_from_and_to_values_return_422(): void
    {
        $landlord = $this->createLandlord();
        $service = $this->createService($this->createBoardingHouse($landlord));

        $this->withBearerToken($landlord)->postJson("/api/services/{$service->id}/price-tiers", [
            'from_quantity' => -1,
            'to_quantity' => -2,
            'unit_price' => -1,
            'tier_order' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors(['from_quantity', 'to_quantity', 'unit_price', 'tier_order']);
    }

    public function test_update_ignores_itself_but_cannot_break_tier_chain(): void
    {
        $landlord = $this->createLandlord();
        $service = $this->createService($this->createBoardingHouse($landlord));
        $first = $this->createTier($service, ['from_quantity' => 0, 'to_quantity' => 50, 'tier_order' => 1]);
        $second = $this->createTier($service, ['from_quantity' => 50, 'to_quantity' => null, 'tier_order' => 2]);

        $this->withBearerToken($landlord)->putJson("/api/service-price-tiers/{$second->id}", $this->tierPayload([
            'from_quantity' => 50, 'to_quantity' => null, 'tier_order' => 2, 'unit_price' => 3000,
        ]))->assertOk()->assertJsonPath('data', null);

        $this->assertDatabaseHas('service_price_tiers', ['id' => $second->id, 'unit_price' => 3000]);

        $this->withBearerToken($landlord)->putJson("/api/service-price-tiers/{$first->id}", $this->tierPayload([
            'from_quantity' => 0, 'to_quantity' => 60, 'tier_order' => 1,
        ]))->assertStatus(422)->assertJsonValidationErrors('from_quantity');
    }

    private function createLandlord(): Landlord
    {
        $user = User::factory()->create();

        return Landlord::create(['user_id' => $user->id, 'full_name' => $user->name, 'phone' => '+84901234567']);
    }

    private function createBoardingHouse(Landlord $landlord): BoardingHouse
    {
        return BoardingHouse::create(['landlord_id' => $landlord->id, 'name' => 'House', 'address' => 'Address']);
    }

    private function createService(BoardingHouse $boardingHouse, array $attributes = []): Service
    {
        return Service::create(['boarding_house_id' => $boardingHouse->id, 'name' => 'Electricity', 'type' => 'ELECTRICITY', 'billing_method' => 'TIERED', 'unit' => 'kWh', 'base_price' => null, ...$attributes]);
    }

    private function createTier(Service $service, array $attributes = []): ServicePriceTier
    {
        return ServicePriceTier::create(['service_id' => $service->id, ...$this->tierPayload(), ...$attributes]);
    }

    private function withBearerToken(Landlord $landlord): static
    {
        return $this->withToken($landlord->user->createToken('test-token')->plainTextToken);
    }

    private function tierPayload(array $attributes = []): array
    {
        return ['from_quantity' => 0, 'to_quantity' => 50, 'unit_price' => 1900, 'tier_order' => 1, ...$attributes];
    }
}
