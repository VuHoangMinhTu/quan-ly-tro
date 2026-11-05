<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingV2FeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_flag_defaults_off_in_application_config_and_is_reported_to_ui(): void
    {
        config(['billing.v2_enabled' => false]);
        $this->actingAs(User::factory()->create(), 'sanctum');

        $this->getJson('/api/billing-v2/status')->assertOk()->assertJsonPath('data.enabled', false);
        $this->postJson('/api/billing-v2/boarding-houses/1/monthly-invoices', ['billing_period' => '2026-10-01'])
            ->assertStatus(503)->assertJsonPath('success', false);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_emergency_disable_blocks_billing_v2_writes_but_preserves_read_only_access_without_fallback(): void
    {
        config(['billing.v2_enabled' => false]);
        $this->actingAs(User::factory()->create(), 'sanctum');

        $this->getJson('/api/billing-v2/boarding-houses/1/monthly-options?billing_period=2026-10-01')->assertStatus(503);
        $this->getJson('/api/billing-v2/rooms/1?billing_period=2026-10-01')->assertStatus(503);
        $this->postJson('/api/billing-v2/meters/1/checkpoints', [])->assertStatus(503);
        $this->postJson('/api/billing-v2/contracts/1/checkout', [])->assertStatus(503);
        $this->postJson('/api/billing-v2/contracts/1/deposits', [])->assertStatus(503);
        $this->postJson('/api/billing-v2/rooms/1/monthly-recalculations/preview', [])->assertStatus(503);
        $this->postJson('/api/billing-v2/invoices/1/issue', [])->assertStatus(503);
        $this->postJson('/api/rooms/1/invoices', [])->assertUnprocessable();
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_global_switch_enables_v2_for_all_authenticated_users_without_pilot_list(): void
    {
        config(['billing.v2_enabled' => true]);

        $first = User::factory()->create();
        $second = User::factory()->create();

        foreach ([$first, $second] as $user) {
            $this->actingAs($user, 'sanctum');

            $this->getJson('/api/billing-v2/status')
                ->assertOk()
                ->assertJsonPath('data.enabled', true);

            $this->postJson('/api/billing-v2/meters/999999/checkpoints', [])
                ->assertNotFound();
        }
    }

    public function test_without_pilot_gate_unrelated_user_still_cannot_access_another_house(): void
    {
        config(['billing.v2_enabled' => true]);

        $contract = \App\Models\Contract::factory()->create();
        $room = $contract->room;

        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum');

        $this->getJson('/api/billing-v2/status')
            ->assertOk()
            ->assertJsonPath('data.enabled', true);

        $this->getJson('/api/billing-v2/rooms/'.$room->id.'?billing_period=2026-10-01')
            ->assertNotFound();

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_v2_still_requires_authentication(): void
    {
        config(['billing.v2_enabled' => true]);

        $this->postJson('/api/billing-v2/meters/1/checkpoints', [])
            ->assertUnauthorized();
    }
}
