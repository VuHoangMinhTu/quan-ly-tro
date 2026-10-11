<?php

namespace Tests\Feature;

use App\Models\BoardingHouse;
use App\Models\Contract;
use App\Models\Landlord;
use App\Models\Room;
use App\Models\RoomTenant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class TenantFilterApiTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['full_name', '  mINh  ', 'Minh Nguyen'])]
    #[TestWith(['phone', '0385528994', '0385528994'])]
    #[TestWith(['email', 'minh@example.com', 'minh@example.com'])]
    #[TestWith(['identity_number', '281326696', '281326696'])]
    public function test_search_matches_each_supported_field_without_leaking_foreign_tenants(string $field, string $term, string $value): void
    {
        $landlord = $this->createLandlord();
        $match = $this->createTenant($landlord, [$field => $value]);
        $this->createTenant($landlord);
        $this->createTenant($this->createLandlord(), [$field => $value]);

        $this->assertTenantIds($landlord, ['q' => $term], [$match->id]);
    }

    public function test_house_and_room_filters_only_match_current_residence_not_old_contracts_or_memberships(): void
    {
        $landlord = $this->createLandlord();
        $firstRoom = $this->createRoom($this->createHouse($landlord));
        $secondRoom = $this->createRoom($this->createHouse($landlord));
        $current = $this->createTenant($landlord);
        $old = $this->createTenant($landlord);
        $other = $this->createTenant($landlord);
        $this->createMembership($firstRoom, $current);
        $this->createMembership($firstRoom, $old, ['status' => 'MOVED_OUT', 'move_out_date' => '2026-09-30']);
        $this->createMembership($secondRoom, $old);
        $this->createMembership($secondRoom, $other);
        $this->createContract($firstRoom, $old, 'EXPIRED');

        $this->assertTenantIds($landlord, ['boarding_house_id' => $firstRoom->boarding_house_id], [$current->id]);
        $this->assertTenantIds($landlord, ['room_id' => $firstRoom->id], [$current->id]);
        $this->assertTenantIds($landlord, ['boarding_house_id' => $firstRoom->boarding_house_id, 'room_id' => $secondRoom->id], []);
    }

    #[TestWith(['ACTIVE'])]
    #[TestWith(['MOVED_OUT'])]
    #[TestWith(['UNASSIGNED'])]
    public function test_occupancy_status_distinguishes_current_former_and_never_assigned_tenants(string $status): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createHouse($landlord));
        $active = $this->createTenant($landlord);
        $moved = $this->createTenant($landlord);
        $unassigned = $this->createTenant($landlord);
        $this->createMembership($room, $active, ['status' => 'MOVED_OUT', 'move_out_date' => '2026-09-30']);
        $this->createMembership($room, $active);
        $this->createMembership($room, $moved, ['status' => 'MOVED_OUT', 'move_out_date' => '2026-09-30']);
        $expected = ['ACTIVE' => $active->id, 'MOVED_OUT' => $moved->id, 'UNASSIGNED' => $unassigned->id];

        $this->assertTenantIds($landlord, ['occupancy_status' => $status], [$expected[$status]]);
    }

    #[TestWith(['PRIMARY'])]
    #[TestWith(['ROOMMATE'])]
    public function test_role_filters_match_the_same_active_room_membership(string $role): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createHouse($landlord));
        $otherRoom = $this->createRoom($this->createHouse($landlord));
        $primary = $this->createTenant($landlord);
        $roommate = $this->createTenant($landlord);
        $former = $this->createTenant($landlord);
        $this->createMembership($room, $primary, ['is_primary' => true]);
        $this->createMembership($otherRoom, $primary, ['is_primary' => false]);
        $this->createMembership($room, $roommate, ['is_primary' => false]);
        $this->createMembership($room, $former, ['is_primary' => true, 'status' => 'MOVED_OUT', 'move_out_date' => '2026-09-30']);
        $expected = $role === 'PRIMARY' ? $primary->id : $roommate->id;

        $this->assertTenantIds($landlord, ['room_id' => $room->id, 'role' => $role], [$expected]);
    }

    #[TestWith(['ACTIVE'])]
    #[TestWith(['EXPIRED'])]
    #[TestWith(['TERMINATED'])]
    #[TestWith(['NONE'])]
    public function test_contract_filter_only_uses_named_tenant_contracts(string $status): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createHouse($landlord));
        $expected = [];
        foreach (['ACTIVE', 'EXPIRED', 'TERMINATED'] as $contractStatus) {
            $tenant = $this->createTenant($landlord);
            $this->createContract($room, $tenant, $contractStatus);
            $expected[$contractStatus] = $tenant->id;
        }
        $roommate = $this->createTenant($landlord);
        $this->createMembership($room, $roommate);
        $expected['NONE'] = $roommate->id;

        $this->assertTenantIds($landlord, ['contract_status' => $status], [$expected[$status]]);
    }

    public function test_multiple_memberships_and_contracts_never_duplicate_tenant_and_response_has_current_residence(): void
    {
        $landlord = $this->createLandlord();
        $oldRoom = $this->createRoom($this->createHouse($landlord));
        $currentRoom = $this->createRoom($this->createHouse($landlord));
        $tenant = $this->createTenant($landlord);
        $this->createMembership($oldRoom, $tenant, ['status' => 'MOVED_OUT', 'move_out_date' => '2026-09-30']);
        $this->createMembership($currentRoom, $tenant, ['is_primary' => true]);
        $this->createContract($oldRoom, $tenant, 'EXPIRED');
        $this->createContract($currentRoom, $tenant, 'EXPIRED');

        $this->withToken($landlord->user->createToken('test-token')->plainTextToken)
            ->getJson('/api/tenants?contract_status=EXPIRED&occupancy_status=ACTIVE')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $tenant->id)
            ->assertJsonPath('data.0.current_residence', [
                'room_id' => $currentRoom->id,
                'room_code' => $currentRoom->room_code,
                'boarding_house_id' => $currentRoom->boarding_house_id,
                'boarding_house_name' => $currentRoom->boardingHouse->name,
                'is_primary' => true,
            ]);
    }

    public function test_current_residence_is_null_without_active_membership_and_loaded_for_each_tenant(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createHouse($landlord));
        $primary = $this->createTenant($landlord);
        $roommate = $this->createTenant($landlord);
        $moved = $this->createTenant($landlord);
        $never = $this->createTenant($landlord);
        $this->createMembership($room, $primary, ['is_primary' => true]);
        $this->createMembership($room, $roommate);
        $this->createMembership($room, $moved, ['status' => 'MOVED_OUT', 'move_out_date' => '2026-09-30']);

        $response = $this->withToken($landlord->user->createToken('test-token')->plainTextToken)
            ->getJson('/api/tenants')->assertOk()->assertJsonCount(4, 'data');
        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertSame($room->id, $rows[$primary->id]['current_residence']['room_id']);
        $this->assertSame($room->id, $rows[$roommate->id]['current_residence']['room_id']);
        $this->assertNull($rows[$moved->id]['current_residence']);
        $this->assertNull($rows[$never->id]['current_residence']);
    }

    public function test_combined_filters_return_only_matching_tenant(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createHouse($landlord));
        $match = $this->createTenant($landlord, ['full_name' => 'Minh']);
        $other = $this->createTenant($landlord, ['full_name' => 'Minh roommate']);
        $this->createMembership($room, $match, ['is_primary' => true]);
        $this->createMembership($room, $other);
        $this->createContract($room, $match, 'ACTIVE');

        $this->assertTenantIds($landlord, [
            'q' => 'Minh', 'boarding_house_id' => $room->boarding_house_id,
            'room_id' => $room->id, 'occupancy_status' => 'ACTIVE',
            'role' => 'PRIMARY', 'contract_status' => 'ACTIVE',
        ], [$match->id]);
    }

    public function test_foreign_house_and_room_filters_return_404_without_leaking_residence(): void
    {
        $landlord = $this->createLandlord();
        $other = $this->createLandlord();
        $foreignRoom = $this->createRoom($this->createHouse($other));
        $foreignTenant = $this->createTenant($other);
        $this->createMembership($foreignRoom, $foreignTenant);
        $localTenant = $this->createTenant($landlord);
        $this->createMembership($foreignRoom, $localTenant);

        $this->withToken($landlord->user->createToken('test-token')->plainTextToken)
            ->getJson('/api/tenants?boarding_house_id='.$foreignRoom->boarding_house_id)->assertNotFound();
        $this->getJson('/api/tenants?room_id='.$foreignRoom->id)->assertNotFound();
        $this->getJson('/api/tenants')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $localTenant->id)->assertJsonPath('data.0.current_residence', null);
    }

    #[TestWith(['q', ['invalid']])]
    #[TestWith(['boarding_house_id', 'invalid'])]
    #[TestWith(['room_id', -1])]
    #[TestWith(['occupancy_status', 'INVALID'])]
    #[TestWith(['role', 'INVALID'])]
    #[TestWith(['contract_status', 'DRAFT'])]
    public function test_invalid_filter_returns_422(string $field, mixed $value): void
    {
        $landlord = $this->createLandlord();

        $this->withToken($landlord->user->createToken('test-token')->plainTextToken)
            ->getJson('/api/tenants?'.http_build_query([$field => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($field)->assertJsonPath('success', false);
    }

    private function assertTenantIds(Landlord $landlord, array $filters, array $ids): void
    {
        $response = $this->withToken($landlord->user->createToken('test-token')->plainTextToken)
            ->getJson('/api/tenants?'.http_build_query($filters))->assertOk()->assertJsonPath('success', true);
        $this->assertEqualsCanonicalizing($ids, array_column($response->json('data'), 'id'));
    }

    private function createLandlord(): Landlord
    {
        $user = User::factory()->create();

        return Landlord::create(['user_id' => $user->id, 'full_name' => $user->name, 'phone' => '0901234567']);
    }

    private function createHouse(Landlord $landlord): BoardingHouse
    {
        return BoardingHouse::create(['landlord_id' => $landlord->id, 'name' => 'Nhà trọ Dĩ An', 'address' => 'Dĩ An']);
    }

    private function createRoom(BoardingHouse $house): Room
    {
        return Room::create(['boarding_house_id' => $house->id, 'room_code' => 'P'.Room::query()->count(), 'monthly_rent' => 2000000, 'status' => 'AVAILABLE']);
    }

    private function createTenant(Landlord $landlord, array $attributes = []): Tenant
    {
        return Tenant::create(['landlord_id' => $landlord->id, 'full_name' => 'Tenant '.Tenant::query()->count(), ...$attributes]);
    }

    private function createMembership(Room $room, Tenant $tenant, array $attributes = []): RoomTenant
    {
        return RoomTenant::create(['room_id' => $room->id, 'tenant_id' => $tenant->id, 'move_in_date' => '2026-10-01', 'status' => 'ACTIVE', 'is_primary' => false, ...$attributes]);
    }

    private function createContract(Room $room, Tenant $tenant, string $status): Contract
    {
        return Contract::create(['room_id' => $room->id, 'tenant_id' => $tenant->id, 'contract_code' => 'HD-'.Contract::query()->count(), 'start_date' => '2026-09-01', 'monthly_rent' => 2000000, 'status' => $status]);
    }
}
