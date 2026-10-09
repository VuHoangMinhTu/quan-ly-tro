<?php

namespace Tests\Feature;

use App\Models\Landlord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_tenants(): void
    {
        $tenant = $this->createTenant($this->createLandlord());

        $this->getJson('/api/tenants')->assertUnauthorized();
        $this->postJson('/api/tenants', $this->tenantPayload())->assertUnauthorized();
        $this->getJson("/api/tenants/{$tenant->id}")->assertUnauthorized();
        $this->putJson("/api/tenants/{$tenant->id}", $this->tenantPayload())->assertUnauthorized();
        $this->deleteJson("/api/tenants/{$tenant->id}")->assertUnauthorized();
    }

    public function test_landlord_can_create_a_tenant(): void
    {
        $landlord = $this->createLandlord();
        $payload = $this->tenantPayload();

        $this->withBearerToken($landlord)->postJson('/api/tenants', $payload)
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Tạo người thuê thành công.')
            ->assertJsonPath('data', null);

        $this->assertDatabaseHas('tenants', [
            'landlord_id' => $landlord->id,
            'full_name' => $payload['full_name'],
        ]);
    }

    public function test_landlord_id_is_assigned_automatically(): void
    {
        $landlord = $this->createLandlord();

        $this->withBearerToken($landlord)->postJson('/api/tenants', [
            ...$this->tenantPayload(),
            'landlord_id' => 99999,
        ])->assertCreated()
            ->assertJsonPath('data', null);

        $this->assertDatabaseHas('tenants', ['landlord_id' => $landlord->id]);
        $this->assertDatabaseMissing('tenants', ['landlord_id' => 99999]);
    }

    public function test_landlord_can_list_only_their_own_tenants(): void
    {
        $landlord = $this->createLandlord();
        $otherLandlord = $this->createLandlord();
        $first = $this->createTenant($landlord, [
            'full_name' => 'First Tenant',
            'date_of_birth' => '2000-01-15',
            'identity_issue_date' => '2020-01-15',
        ]);
        $second = $this->createTenant($landlord, ['full_name' => 'Second Tenant']);
        $other = $this->createTenant($otherLandlord, ['full_name' => 'Other Tenant']);

        $response = $this->withBearerToken($landlord)->getJson('/api/tenants')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($first->id, $ids);
        $this->assertContains($second->id, $ids);
        $this->assertNotContains($other->id, $ids);

        $firstTenant = collect($response->json('data'))->firstWhere('id', $first->id);
        $this->assertSame('2000-01-15', $firstTenant['date_of_birth']);
        $this->assertSame('2020-01-15', $firstTenant['identity_issue_date']);
    }

    public function test_landlord_can_view_their_own_tenant(): void
    {
        $landlord = $this->createLandlord();
        $tenant = $this->createTenant($landlord, [
            'date_of_birth' => '2000-01-15',
            'identity_issue_date' => '2020-01-15',
        ]);

        $this->withBearerToken($landlord)->getJson("/api/tenants/{$tenant->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $tenant->id)
            ->assertJsonPath('data.landlord_id', $landlord->id)
            ->assertJsonPath('data.date_of_birth', '2000-01-15')
            ->assertJsonPath('data.identity_issue_date', '2020-01-15');
    }

    public function test_landlord_cannot_view_another_landlords_tenant(): void
    {
        $tenant = $this->createTenant($this->createLandlord());
        $otherLandlord = $this->createLandlord();

        $this->withBearerToken($otherLandlord)->getJson("/api/tenants/{$tenant->id}")
            ->assertNotFound();
    }

    public function test_landlord_can_update_their_own_tenant(): void
    {
        $landlord = $this->createLandlord();
        $tenant = $this->createTenant($landlord);

        $this->withBearerToken($landlord)->putJson("/api/tenants/{$tenant->id}", $this->tenantPayload([
            'full_name' => 'Updated Tenant',
            'gender' => 'FEMALE',
        ]))->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'full_name' => 'Updated Tenant', 'gender' => 'FEMALE']);
    }

    public function test_landlord_cannot_update_another_landlords_tenant(): void
    {
        $tenant = $this->createTenant($this->createLandlord(), ['full_name' => 'Protected Tenant']);
        $otherLandlord = $this->createLandlord();

        $this->withBearerToken($otherLandlord)->putJson("/api/tenants/{$tenant->id}", $this->tenantPayload([
            'full_name' => 'Changed Tenant',
        ]))->assertNotFound();

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'full_name' => 'Protected Tenant']);
    }

    public function test_landlord_can_delete_their_own_tenant(): void
    {
        $landlord = $this->createLandlord();
        $tenant = $this->createTenant($landlord);

        $this->withBearerToken($landlord)->deleteJson("/api/tenants/{$tenant->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Xóa người thuê thành công.')
            ->assertJsonPath('data', null);

        $this->assertSoftDeleted('tenants', ['id' => $tenant->id]);
    }

    public function test_landlord_cannot_delete_another_landlords_tenant(): void
    {
        $tenant = $this->createTenant($this->createLandlord());
        $otherLandlord = $this->createLandlord();

        $this->withBearerToken($otherLandlord)->deleteJson("/api/tenants/{$tenant->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
    }

    public function test_validation_returns_422(): void
    {
        $landlord = $this->createLandlord();

        $this->withBearerToken($landlord)->postJson('/api/tenants', [
            'full_name' => '',
            'phone' => str_repeat('1', 21),
            'email' => 'not-an-email',
            'date_of_birth' => 'invalid-date',
            'gender' => 'INVALID',
            'identity_number' => str_repeat('1', 31),
            'identity_issue_place' => str_repeat('A', 256),
            'identity_issue_date' => 'invalid-date',
            'permanent_address' => str_repeat('A', 501),
        ])->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors([
                'full_name', 'phone', 'email', 'date_of_birth', 'gender',
                'identity_number', 'identity_issue_place', 'identity_issue_date', 'permanent_address',
            ]);
    }

    public function test_future_tenant_dates_return_422(): void
    {
        $landlord = $this->createLandlord();

        $this->withBearerToken($landlord)->postJson('/api/tenants', $this->tenantPayload([
            'date_of_birth' => now()->addDay()->toDateString(),
            'identity_issue_date' => now()->addDay()->toDateString(),
        ]))->assertStatus(422)
            ->assertJsonValidationErrors(['date_of_birth', 'identity_issue_date']);
    }

    private function createLandlord(): Landlord
    {
        $user = User::factory()->create();

        return Landlord::create([
            'user_id' => $user->id,
            'full_name' => $user->name,
            'phone' => '+84901234567',
        ]);
    }

    private function createTenant(Landlord $landlord, array $attributes = []): Tenant
    {
        return Tenant::create([
            'landlord_id' => $landlord->id,
            ...$this->tenantPayload(),
            ...$attributes,
        ]);
    }

    private function withBearerToken(Landlord $landlord): static
    {
        return $this->withToken($landlord->user->createToken('test-token')->plainTextToken);
    }

    private function tenantPayload(array $attributes = []): array
    {
        return [
            'full_name' => 'Nguyen Van Tenant',
            'phone' => '0901234567',
            'email' => 'tenant@example.com',
            'date_of_birth' => '2000-01-15',
            'gender' => 'MALE',
            'identity_number' => '012345678901',
            'identity_issue_place' => 'Ho Chi Minh City',
            'identity_issue_date' => '2020-01-15',
            'permanent_address' => '123 Nguyen Trai, District 1',
            ...$attributes,
        ];
    }
}
