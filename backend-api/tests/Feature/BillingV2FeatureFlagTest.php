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

    public function test_global_flag_and_explicit_pilot_allowlist_are_both_required(): void
    {
        $pilot = User::factory()->create();
        $other = User::factory()->create();
        config(['billing.v2_enabled' => true, 'billing.v2_pilot_user_ids' => [$pilot->id]]);

        $this->actingAs($pilot, 'sanctum');
        $this->getJson('/api/billing-v2/status')->assertOk()->assertJsonPath('data.enabled', true);
        // The request passes the feature gate and reaches the owner-scoped controller lookup.
        $this->postJson('/api/billing-v2/meters/999999/checkpoints', [])->assertNotFound();

        $this->actingAs($other, 'sanctum');
        $this->getJson('/api/billing-v2/status')->assertOk()->assertJsonPath('data.enabled', false);
        $this->getJson('/api/billing-v2/rooms/1?billing_period=2026-10-01')->assertStatus(503);
        $this->postJson('/api/billing-v2/boarding-houses/1/monthly-invoices', ['billing_period' => '2026-10-01'])
            ->assertStatus(503);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_empty_pilot_allowlist_fails_closed_even_when_global_switch_is_on(): void
    {
        config(['billing.v2_enabled' => true, 'billing.v2_pilot_user_ids' => []]);
        $this->actingAs(User::factory()->create(), 'sanctum');

        $this->getJson('/api/billing-v2/status')->assertOk()->assertJsonPath('data.enabled', false);
        $this->postJson('/api/billing-v2/boarding-houses/1/monthly-invoices', ['billing_period' => '2026-10-01'])
            ->assertStatus(503);
        $this->assertDatabaseCount('invoices', 0);
    }
}
