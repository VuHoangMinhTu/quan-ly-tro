<?php

namespace Tests\Feature;

use App\Models\BoardingHouse;
use App\Models\Landlord;
use App\Models\Room;
use App\Models\RoomTenant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomTenantApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_room_tenants(): void
    {
        $room = $this->createRoom($this->createBoardingHouse($this->createLandlord()));
        $roomTenant = $this->createRoomTenant($room, $this->createTenant($room->boardingHouse->landlord));

        $this->getJson("/api/rooms/{$room->id}/tenants")->assertUnauthorized();
        $this->postJson("/api/rooms/{$room->id}/tenants", $this->roomTenantPayload())->assertUnauthorized();
        $this->putJson("/api/room-tenants/{$roomTenant->id}", $this->roomTenantPayload())->assertUnauthorized();
        $this->deleteJson("/api/room-tenants/{$roomTenant->id}")->assertUnauthorized();
    }

    public function test_landlord_can_add_own_tenant_to_own_room(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($landlord));
        $tenant = $this->createTenant($landlord);

        $this->withBearerToken($landlord)->postJson("/api/rooms/{$room->id}/tenants", $this->roomTenantPayload([
            'tenant_id' => $tenant->id,
            'room_id' => 99999,
            'is_primary' => true,
        ]))->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Thêm người ở trong phòng thành công.')
            ->assertJsonPath('data', null);

        $this->assertDatabaseHas('room_tenants', [
            'room_id' => $room->id,
            'tenant_id' => $tenant->id,
            'is_primary' => true,
            'status' => 'ACTIVE',
        ]);
        $this->assertDatabaseMissing('room_tenants', ['room_id' => 99999]);
    }

    public function test_returns_404_when_adding_another_landlords_tenant_or_room(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($landlord));
        $otherRoom = $this->createRoom($this->createBoardingHouse($this->createLandlord()));
        $otherTenant = $this->createTenant($this->createLandlord());

        $this->withBearerToken($landlord)->postJson("/api/rooms/{$otherRoom->id}/tenants", $this->roomTenantPayload())
            ->assertNotFound();
        $this->withBearerToken($landlord)->postJson("/api/rooms/{$room->id}/tenants", $this->roomTenantPayload([
            'tenant_id' => $otherTenant->id,
        ]))->assertNotFound();

        $this->assertDatabaseCount('room_tenants', 0);
    }

    public function test_returns_422_for_duplicate_active_membership(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($landlord));
        $tenant = $this->createTenant($landlord);
        $this->createRoomTenant($room, $tenant);

        $this->withBearerToken($landlord)->postJson("/api/rooms/{$room->id}/tenants", $this->roomTenantPayload([
            'tenant_id' => $tenant->id,
        ]))->assertStatus(422)
            ->assertJsonValidationErrors('tenant_id');
    }

    public function test_landlord_can_add_tenant_after_previous_membership_moved_out(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($landlord));
        $tenant = $this->createTenant($landlord);
        $this->createRoomTenant($room, $tenant, [
            'status' => 'MOVED_OUT',
            'move_out_date' => '2026-09-30',
        ]);

        $this->withBearerToken($landlord)->postJson("/api/rooms/{$room->id}/tenants", $this->roomTenantPayload([
            'tenant_id' => $tenant->id,
            'move_in_date' => '2026-10-01',
        ]))->assertCreated()
            ->assertJsonPath('data', null);

        $this->assertDatabaseCount('room_tenants', 2);
        $this->assertDatabaseHas('room_tenants', ['room_id' => $room->id, 'tenant_id' => $tenant->id, 'status' => 'ACTIVE']);
    }

    public function test_returns_422_when_creating_a_second_active_primary_tenant(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($landlord));
        $this->createRoomTenant($room, $this->createTenant($landlord), ['is_primary' => true]);
        $tenant = $this->createTenant($landlord);

        $this->withBearerToken($landlord)->postJson("/api/rooms/{$room->id}/tenants", $this->roomTenantPayload([
            'tenant_id' => $tenant->id,
            'is_primary' => true,
        ]))->assertStatus(422)
            ->assertJsonValidationErrors('is_primary');
    }

    public function test_returns_422_when_updating_to_a_second_active_primary_tenant(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($landlord));
        $this->createRoomTenant($room, $this->createTenant($landlord), ['is_primary' => true]);
        $roomTenant = $this->createRoomTenant($room, $this->createTenant($landlord));

        $this->withBearerToken($landlord)->putJson("/api/room-tenants/{$roomTenant->id}", $this->roomTenantPayload([
            'tenant_id' => $roomTenant->tenant_id,
            'room_id' => 99999,
            'is_primary' => true,
        ]))->assertStatus(422)
            ->assertJsonValidationErrors('is_primary');
    }

    public function test_landlord_can_list_own_room_tenants_with_tenant_relationship(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($landlord));
        $activeTenant = $this->createTenant($landlord, ['full_name' => 'Active tenant']);
        $movedOutTenant = $this->createTenant($landlord, ['full_name' => 'Moved out tenant']);
        $active = $this->createRoomTenant($room, $activeTenant, ['move_in_date' => '2026-10-01']);
        $this->createRoomTenant($room, $movedOutTenant, [
            'status' => 'MOVED_OUT',
            'move_in_date' => '2026-09-01',
            'move_out_date' => '2026-09-30',
        ]);

        $this->withBearerToken($landlord)->getJson("/api/rooms/{$room->id}/tenants")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $active->id)
            ->assertJsonPath('data.0.tenant.id', $activeTenant->id)
            ->assertJsonPath('data.0.move_in_date', '2026-10-01')
            ->assertJsonPath('data.0.move_out_date', null)
            ->assertJsonPath('data.1.move_in_date', '2026-09-01')
            ->assertJsonPath('data.1.move_out_date', '2026-09-30');
    }

    public function test_returns_404_when_listing_another_landlords_room_tenants(): void
    {
        $room = $this->createRoom($this->createBoardingHouse($this->createLandlord()));
        $landlord = $this->createLandlord();

        $this->withBearerToken($landlord)->getJson("/api/rooms/{$room->id}/tenants")->assertNotFound();
    }

    public function test_landlord_can_update_own_room_tenant(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($landlord));
        $roomTenant = $this->createRoomTenant($room, $this->createTenant($landlord));

        $this->withBearerToken($landlord)->putJson("/api/room-tenants/{$roomTenant->id}", $this->roomTenantPayload([
            'tenant_id' => $roomTenant->tenant_id,
            'is_primary' => true,
            'move_in_date' => '2026-10-01',
        ]))->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Cập nhật người ở trong phòng thành công.')
            ->assertJsonPath('data', null);

        $this->assertDatabaseHas('room_tenants', [
            'id' => $roomTenant->id,
            'room_id' => $room->id,
            'is_primary' => true,
            'move_in_date' => '2026-10-01',
        ]);
    }

    public function test_returns_404_when_updating_another_landlords_room_tenant(): void
    {
        $otherLandlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($otherLandlord));
        $roomTenant = $this->createRoomTenant($room, $this->createTenant($otherLandlord));
        $landlord = $this->createLandlord();

        $this->withBearerToken($landlord)->putJson("/api/room-tenants/{$roomTenant->id}", $this->roomTenantPayload())
            ->assertNotFound();

        $this->assertDatabaseHas('room_tenants', ['id' => $roomTenant->id, 'move_in_date' => '2026-09-01']);
    }

    public function test_returns_404_when_updating_to_another_landlords_tenant(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($landlord));
        $roomTenant = $this->createRoomTenant($room, $this->createTenant($landlord));
        $otherTenant = $this->createTenant($this->createLandlord());

        $this->withBearerToken($landlord)->putJson("/api/room-tenants/{$roomTenant->id}", $this->roomTenantPayload([
            'tenant_id' => $otherTenant->id,
        ]))->assertNotFound();

        $this->assertDatabaseHas('room_tenants', ['id' => $roomTenant->id, 'tenant_id' => $roomTenant->tenant_id]);
    }

    public function test_status_and_move_out_date_rules_return_422(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($landlord));
        $tenant = $this->createTenant($landlord);

        $this->withBearerToken($landlord)->postJson("/api/rooms/{$room->id}/tenants", $this->roomTenantPayload([
            'tenant_id' => $tenant->id,
            'move_out_date' => '2026-09-30',
        ]))->assertStatus(422)
            ->assertJsonValidationErrors('move_out_date');

        $this->withBearerToken($landlord)->postJson("/api/rooms/{$room->id}/tenants", $this->roomTenantPayload([
            'tenant_id' => $tenant->id,
            'status' => 'MOVED_OUT',
            'move_out_date' => null,
        ]))->assertStatus(422)
            ->assertJsonValidationErrors('move_out_date');

        $this->withBearerToken($landlord)->postJson("/api/rooms/{$room->id}/tenants", $this->roomTenantPayload([
            'tenant_id' => $tenant->id,
            'status' => 'MOVED_OUT',
            'move_out_date' => '2026-08-31',
        ]))->assertStatus(422)
            ->assertJsonValidationErrors('move_out_date');
    }

    public function test_landlord_can_delete_own_room_tenant(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($landlord));
        $roomTenant = $this->createRoomTenant($room, $this->createTenant($landlord));

        $this->withBearerToken($landlord)->deleteJson("/api/room-tenants/{$roomTenant->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Xóa người ở trong phòng thành công.')
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('room_tenants', ['id' => $roomTenant->id]);
    }

    public function test_returns_404_when_deleting_another_landlords_room_tenant(): void
    {
        $otherLandlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($otherLandlord));
        $roomTenant = $this->createRoomTenant($room, $this->createTenant($otherLandlord));
        $landlord = $this->createLandlord();

        $this->withBearerToken($landlord)->deleteJson("/api/room-tenants/{$roomTenant->id}")->assertNotFound();

        $this->assertDatabaseHas('room_tenants', ['id' => $roomTenant->id]);
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
        return Room::create([
            'boarding_house_id' => $boardingHouse->id,
            'room_code' => 'P'.Room::query()->count(),
            'monthly_rent' => 2000000,
            'status' => 'AVAILABLE',
        ]);
    }

    private function createTenant(Landlord $landlord, array $attributes = []): Tenant
    {
        return Tenant::create(['landlord_id' => $landlord->id, 'full_name' => 'Tenant '.Tenant::query()->count(), ...$attributes]);
    }

    private function createRoomTenant(Room $room, Tenant $tenant, array $attributes = []): RoomTenant
    {
        return RoomTenant::create([
            ...$this->roomTenantPayload(),
            'room_id' => $room->id,
            'tenant_id' => $tenant->id,
            ...$attributes,
        ]);
    }

    private function withBearerToken(Landlord $landlord): static
    {
        return $this->withToken($landlord->user->createToken('test-token')->plainTextToken);
    }

    private function roomTenantPayload(array $attributes = []): array
    {
        return [
            'tenant_id' => 1,
            'is_primary' => false,
            'move_in_date' => '2026-09-01',
            'move_out_date' => null,
            'status' => 'ACTIVE',
            ...$attributes,
        ];
    }
}
