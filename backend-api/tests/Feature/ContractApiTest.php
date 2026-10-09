<?php

namespace Tests\Feature;

use App\Models\BoardingHouse;
use App\Models\Contract;
use App\Models\Landlord;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_contracts(): void
    {
        $room = $this->createRoom($this->createBoardingHouse($this->createLandlord()));

        $this->getJson("/api/rooms/{$room->id}/contracts")->assertUnauthorized();
        $this->postJson("/api/rooms/{$room->id}/contracts", $this->contractPayload())->assertUnauthorized();
        $this->getJson('/api/contracts/1')->assertUnauthorized();
        $this->putJson('/api/contracts/1', $this->contractPayload())->assertUnauthorized();
        $this->deleteJson('/api/contracts/1')->assertUnauthorized();
    }

    public function test_landlord_can_create_contract_for_own_room_and_tenant(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($landlord));
        $tenant = $this->createTenant($landlord);

        $this->withBearerToken($landlord)->postJson("/api/rooms/{$room->id}/contracts", $this->contractPayload([
            'tenant_id' => $tenant->id,
            'room_id' => 99999,
        ]))->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Tạo hợp đồng thành công.')
            ->assertJsonPath('data', null);

        $this->assertDatabaseHas('contracts', [
            'room_id' => $room->id,
            'tenant_id' => $tenant->id,
            'contract_code' => 'HD-001',
        ]);
        $this->assertDatabaseMissing('contracts', ['room_id' => 99999]);
    }

    public function test_landlord_cannot_create_contract_for_another_landlords_room_or_tenant(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($landlord));
        $otherRoom = $this->createRoom($this->createBoardingHouse($this->createLandlord()));
        $otherTenant = $this->createTenant($this->createLandlord());

        $this->withBearerToken($landlord)->postJson("/api/rooms/{$otherRoom->id}/contracts", $this->contractPayload())
            ->assertNotFound();
        $this->withBearerToken($landlord)->postJson("/api/rooms/{$room->id}/contracts", $this->contractPayload([
            'tenant_id' => $otherTenant->id,
        ]))->assertNotFound();
    }

    public function test_contract_code_must_be_unique(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($landlord));
        $tenant = $this->createTenant($landlord);
        $this->createContract($room, $tenant, ['contract_code' => 'HD-001']);

        $this->withBearerToken($landlord)->postJson("/api/rooms/{$room->id}/contracts", $this->contractPayload([
            'tenant_id' => $tenant->id,
        ]))->assertStatus(422)->assertJsonValidationErrors('contract_code');
    }

    public function test_landlord_can_list_own_room_contracts_with_tenant(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($landlord));
        $tenant = $this->createTenant($landlord);
        $contract = $this->createContract($room, $tenant);

        $this->withBearerToken($landlord)->getJson("/api/rooms/{$room->id}/contracts")
            ->assertOk()
            ->assertJsonPath('data.0.id', $contract->id)
            ->assertJsonPath('data.0.tenant.id', $tenant->id)
            ->assertJsonPath('data.0.start_date', '2026-09-01')
            ->assertJsonPath('data.0.end_date', '2026-09-30')
            ->assertJsonPath('data.0.signed_date', '2026-09-01');
    }

    public function test_landlord_cannot_list_another_landlords_room_contracts(): void
    {
        $room = $this->createRoom($this->createBoardingHouse($this->createLandlord()));
        $landlord = $this->createLandlord();

        $this->withBearerToken($landlord)->getJson("/api/rooms/{$room->id}/contracts")->assertNotFound();
    }

    public function test_landlord_can_view_own_contract_but_not_another_landlords_contract(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($landlord));
        $tenant = $this->createTenant($landlord);
        $contract = $this->createContract($room, $tenant);
        $otherContract = $this->createContract(
            $this->createRoom($this->createBoardingHouse($this->createLandlord())),
            $this->createTenant($this->createLandlord()),
            ['contract_code' => 'HD-OTHER']
        );

        $this->withBearerToken($landlord)->getJson("/api/contracts/{$contract->id}")
            ->assertOk()
            ->assertJsonPath('data.room.id', $room->id)
            ->assertJsonPath('data.tenant.id', $tenant->id)
            ->assertJsonPath('data.start_date', '2026-09-01')
            ->assertJsonPath('data.end_date', '2026-09-30')
            ->assertJsonPath('data.signed_date', '2026-09-01');
        $this->withBearerToken($landlord)->getJson("/api/contracts/{$otherContract->id}")->assertNotFound();
    }

    public function test_landlord_can_update_contract_and_code_unique_ignores_itself(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($landlord));
        $tenant = $this->createTenant($landlord);
        $contract = $this->createContract($room, $tenant, ['status' => 'DRAFT']);

        $this->withBearerToken($landlord)->putJson("/api/contracts/{$contract->id}", $this->contractPayload([
            'tenant_id' => $tenant->id,
            'contract_code' => $contract->contract_code,
            'monthly_rent' => 2500000,
            'status' => 'DRAFT',
        ]))->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);

        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'monthly_rent' => 2500000]);
    }

    public function test_landlord_cannot_update_another_landlords_contract_or_use_another_tenant(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($landlord));
        $tenant = $this->createTenant($landlord);
        $contract = $this->createContract($room, $tenant, ['status' => 'DRAFT']);
        $otherTenant = $this->createTenant($this->createLandlord());
        $otherContract = $this->createContract(
            $this->createRoom($this->createBoardingHouse($this->createLandlord())),
            $this->createTenant($this->createLandlord()),
            ['contract_code' => 'HD-OTHER']
        );

        $this->withBearerToken($landlord)->putJson("/api/contracts/{$contract->id}", $this->contractPayload([
            'tenant_id' => $otherTenant->id,
            'status' => 'DRAFT',
        ]))->assertNotFound();
        $this->withBearerToken($landlord)->putJson("/api/contracts/{$otherContract->id}", $this->contractPayload([
            'contract_code' => 'HD-OTHER',
            'status' => 'DRAFT',
        ]))->assertNotFound();
    }

    public function test_active_contracts_cannot_overlap_but_non_overlapping_and_draft_contracts_are_allowed(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($landlord));
        $tenant = $this->createTenant($landlord);
        $this->createContract($room, $tenant, [
            'contract_code' => 'HD-ACTIVE-1', 'status' => 'ACTIVE', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30',
        ]);

        $this->withBearerToken($landlord)->postJson("/api/rooms/{$room->id}/contracts", $this->contractPayload([
            'tenant_id' => $tenant->id, 'contract_code' => 'HD-OVERLAP', 'status' => 'ACTIVE', 'start_date' => '2026-09-15', 'end_date' => '2026-10-15',
        ]))->assertStatus(422)->assertJsonValidationErrors('start_date');

        $this->withBearerToken($landlord)->postJson("/api/rooms/{$room->id}/contracts", $this->contractPayload([
            'tenant_id' => $tenant->id, 'contract_code' => 'HD-NEXT', 'status' => 'ACTIVE', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31',
        ]))->assertCreated()->assertJsonPath('data', null);

        $this->withBearerToken($landlord)->postJson("/api/rooms/{$room->id}/contracts", $this->contractPayload([
            'tenant_id' => $tenant->id, 'contract_code' => 'HD-DRAFT', 'status' => 'DRAFT', 'start_date' => '2026-09-15', 'end_date' => '2026-10-15',
        ]))->assertCreated()->assertJsonPath('data', null);
    }

    public function test_active_contract_update_ignores_itself_for_overlap_validation(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($landlord));
        $tenant = $this->createTenant($landlord);
        $contract = $this->createContract($room, $tenant, [
            'status' => 'ACTIVE', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30',
        ]);

        $this->withBearerToken($landlord)->putJson("/api/contracts/{$contract->id}", $this->contractPayload([
            'tenant_id' => $tenant->id, 'contract_code' => $contract->contract_code, 'status' => 'ACTIVE', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30',
        ]))->assertOk()->assertJsonPath('data', null);
    }

    public function test_open_ended_active_contract_blocks_later_active_contracts(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($landlord));
        $tenant = $this->createTenant($landlord);
        $this->createContract($room, $tenant, [
            'contract_code' => 'HD-OPEN', 'status' => 'ACTIVE', 'start_date' => '2026-09-01', 'end_date' => null,
        ]);

        $this->withBearerToken($landlord)->postJson("/api/rooms/{$room->id}/contracts", $this->contractPayload([
            'tenant_id' => $tenant->id, 'contract_code' => 'HD-LATER', 'status' => 'ACTIVE', 'start_date' => '2027-01-01', 'end_date' => null,
        ]))->assertStatus(422)->assertJsonValidationErrors('start_date');
    }

    public function test_contract_update_cannot_change_room_from_request_body(): void
    {
        $landlord = $this->createLandlord();
        $firstRoom = $this->createRoom($this->createBoardingHouse($landlord));
        $secondRoom = $this->createRoom($this->createBoardingHouse($landlord));
        $tenant = $this->createTenant($landlord);
        $contract = $this->createContract($firstRoom, $tenant, ['status' => 'DRAFT']);

        $this->withBearerToken($landlord)->putJson("/api/contracts/{$contract->id}", $this->contractPayload([
            'tenant_id' => $tenant->id,
            'contract_code' => $contract->contract_code,
            'status' => 'DRAFT',
            'room_id' => $secondRoom->id,
        ]))->assertOk()->assertJsonPath('data', null);

        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'room_id' => $firstRoom->id]);
    }

    public function test_landlord_can_delete_own_contract_but_not_another_landlords_contract(): void
    {
        $landlord = $this->createLandlord();
        $contract = $this->createContract(
            $this->createRoom($this->createBoardingHouse($landlord)),
            $this->createTenant($landlord)
        );
        $otherContract = $this->createContract(
            $this->createRoom($this->createBoardingHouse($this->createLandlord())),
            $this->createTenant($this->createLandlord()),
            ['contract_code' => 'HD-OTHER']
        );

        $this->withBearerToken($landlord)->deleteJson("/api/contracts/{$contract->id}")
            ->assertOk()
            ->assertJsonPath('data', null);
        $this->assertSoftDeleted('contracts', ['id' => $contract->id]);

        $this->withBearerToken($landlord)->deleteJson("/api/contracts/{$otherContract->id}")->assertNotFound();
    }

    public function test_contract_validation_returns_422(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($landlord));

        $this->withBearerToken($landlord)->postJson("/api/rooms/{$room->id}/contracts", [
            'tenant_id' => 999999,
            'contract_code' => '',
            'end_date' => '2026-01-01',
            'start_date' => '2026-02-01',
            'monthly_rent' => -1,
            'deposit_amount' => -1,
            'status' => 'INVALID',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['tenant_id', 'contract_code', 'end_date', 'monthly_rent', 'deposit_amount', 'status']);
    }

    public function test_tenant_id_is_required(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($landlord));

        $this->withBearerToken($landlord)
            ->postJson("/api/rooms/{$room->id}/contracts", collect($this->contractPayload())->except('tenant_id')->all())
            ->assertStatus(422)
            ->assertJsonValidationErrors('tenant_id');
    }

    public function test_start_date_is_required(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($landlord));
        $tenant = $this->createTenant($landlord);

        $this->withBearerToken($landlord)
            ->postJson("/api/rooms/{$room->id}/contracts", collect($this->contractPayload([
                'tenant_id' => $tenant->id,
            ]))->except('start_date')->all())
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_date');
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

    private function createRoom(BoardingHouse $boardingHouse): Room
    {
        return Room::create(['boarding_house_id' => $boardingHouse->id, 'room_code' => 'P'.Room::query()->count(), 'monthly_rent' => 2000000, 'status' => 'AVAILABLE']);
    }

    private function createTenant(Landlord $landlord): Tenant
    {
        return Tenant::create(['landlord_id' => $landlord->id, 'full_name' => 'Tenant '.Tenant::query()->count()]);
    }

    private function createContract(Room $room, Tenant $tenant, array $attributes = []): Contract
    {
        return Contract::create(['room_id' => $room->id, 'tenant_id' => $tenant->id, ...$this->contractPayload(), ...$attributes]);
    }

    private function withBearerToken(Landlord $landlord): static
    {
        return $this->withToken($landlord->user->createToken('test-token')->plainTextToken);
    }

    private function contractPayload(array $attributes = []): array
    {
        return ['tenant_id' => 1, 'contract_code' => 'HD-001', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'signed_date' => '2026-09-01', 'monthly_rent' => 2000000, 'deposit_amount' => 1000000, 'status' => 'DRAFT', 'note' => 'Note', ...$attributes];
    }
}
