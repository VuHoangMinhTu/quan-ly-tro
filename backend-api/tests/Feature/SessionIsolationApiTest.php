<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionIsolationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_switching_owners_scopes_lists_and_returns_404_for_foreign_resources_and_401_for_revoked_token(): void
    {
        $contractA = Contract::factory()->create();
        $contractB = Contract::factory()->create();
        $roomA = $contractA->room;
        $roomB = $contractB->room;
        $userA = $roomA->boardingHouse->landlord->user;
        $userB = $roomB->boardingHouse->landlord->user;
        $invoiceA = $this->draftInvoice($contractA, 'SESSION-A');
        $invoiceB = $this->draftInvoice($contractB, 'SESSION-B');

        $tokenA = $this->loginToken($userA);
        $this->asToken($tokenA)->getJson("/api/boarding-houses/{$roomA->boarding_house_id}/rooms")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $roomA->id);
        $this->asToken($tokenA)->getJson("/api/rooms/{$roomA->id}/invoices")
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $invoiceA->id)
            ->assertJsonPath('data.pagination.total', 1);
        $this->asToken($tokenA)->getJson('/api/tenants')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $contractA->tenant_id);

        $this->asToken($tokenA)->postJson('/api/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $tokenB = $this->loginToken($userB);

        $this->asToken($tokenB)->getJson('/api/me')->assertOk()->assertJsonPath('data.user.id', $userB->id);
        $this->asToken($tokenB)->getJson("/api/boarding-houses/{$roomB->boarding_house_id}/rooms")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $roomB->id);
        $this->asToken($tokenB)->getJson("/api/rooms/{$roomB->id}/invoices")
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $invoiceB->id)
            ->assertJsonPath('data.pagination.total', 1);
        $this->asToken($tokenB)->getJson('/api/tenants')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $contractB->tenant_id);

        $this->asToken($tokenB)->getJson("/api/boarding-houses/{$roomA->boarding_house_id}/rooms")->assertNotFound();
        $this->asToken($tokenB)->getJson("/api/rooms/{$roomA->id}")->assertNotFound();
        $this->asToken($tokenB)->getJson("/api/rooms/{$roomA->id}/invoices")->assertNotFound();
        $this->asToken($tokenB)->getJson("/api/invoices/{$invoiceA->id}")->assertNotFound();
        $this->asToken($tokenB)->getJson("/api/tenants/{$contractA->tenant_id}")->assertNotFound();
        $this->asToken($tokenB)->getJson('/api/tenants?boarding_house_id='.$roomA->boarding_house_id)->assertNotFound();
        $this->asToken($tokenB)->deleteJson("/api/rooms/{$roomA->id}")->assertNotFound();
        $this->asToken($tokenB)->deleteJson("/api/invoices/{$invoiceA->id}")->assertNotFound();
        $this->asToken($tokenB)->deleteJson("/api/tenants/{$contractA->tenant_id}")->assertNotFound();

        $this->asToken($tokenA)->getJson('/api/me')->assertUnauthorized();
        $this->asToken($tokenB)->getJson('/api/me')->assertOk()->assertJsonPath('data.user.id', $userB->id);
        $this->assertModelExists($roomA);
        $this->assertModelExists($invoiceA);
        $this->assertModelExists($contractA->tenant);
        $this->assertDatabaseHas('rooms', ['id' => $roomA->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('invoices', ['id' => $invoiceA->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('tenants', ['id' => $contractA->tenant_id, 'deleted_at' => null]);
    }

    private function asToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function loginToken(User $user): string
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', '')->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('data.user.id', $user->id)->json('data.token');
    }

    private function draftInvoice(Contract $contract, string $code): Invoice
    {
        return $contract->room->invoices()->create([
            'contract_id' => $contract->id, 'invoice_code' => $code, 'billing_period' => '2026-10-01',
            'status' => 'DRAFT', 'subtotal' => 0, 'discount_amount' => 0, 'total_amount' => 0, 'paid_amount' => 0,
        ]);
    }
}
