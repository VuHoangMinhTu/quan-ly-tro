<?php

namespace Tests\Feature;

use App\Models\BoardingHouse;
use App\Models\BoardingHouseResidenceProfile;
use App\Models\Landlord;
use App\Models\ResidenceDeclaration;
use App\Models\Room;
use App\Models\RoomTenant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResidenceDeclarationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authentication_is_required_for_residence_api(): void
    {
        $this->getJson('/api/residence-declarations')->assertUnauthorized();
        $this->getJson('/api/residence/boarding-houses')->assertUnauthorized();
        $this->postJson('/api/residence-declarations', [])->assertUnauthorized();
    }

    public function test_context_only_lists_active_roommates_and_legal_head_not_operational_owner(): void
    {
        [$user, $room, $tenant, $member] = $this->fixture();
        $moved = $this->person($room, 'Đã chuyển đi', 'MOVED_OUT');
        $otherRoom = Room::create(['boarding_house_id' => $room->boarding_house_id, 'room_code' => '102', 'status' => 'AVAILABLE']);
        $foreign = $this->person($otherRoom, 'Phòng khác');
        $this->actingAs($user, 'sanctum')->getJson("/api/residence/rooms/{$room->id}/context")
            ->assertOk()->assertJsonCount(2, 'data.occupants')
            ->assertJsonPath('data.operational_role', 'OWNER')
            ->assertJsonPath('data.room.boarding_house.residence_profile.household_head_name', 'Chủ hộ pháp lý')
            ->assertJsonMissing(['full_name' => $moved->full_name])->assertJsonMissing(['full_name' => $foreign->full_name]);
    }

    public function test_draft_prefills_master_data_and_does_not_accept_frontend_landlord_or_snapshot(): void
    {
        [$user, $room, $tenant, $member] = $this->fixture();
        $payload = [...$this->payload($room, $tenant, $member), 'landlord_id' => 9999, 'status' => 'REGISTERED', 'snapshot' => ['fake' => true], 'full_name' => 'Ghi đè'];
        $response = $this->actingAs($user, 'sanctum')->postJson('/api/residence-declarations', $payload)->assertCreated();
        $id = $response->json('data.id');
        $this->getJson("/api/residence-declarations/{$id}")->assertOk()
            ->assertJsonPath('data.ct01.declarant.full_name', 'Nguyễn Văn A')
            ->assertJsonPath('data.ct01.declarant.date_of_birth', '2000-09-28')
            ->assertJsonPath('data.ct01.members.0.full_name', 'Trần Thị B')
            ->assertJsonPath('data.ct01.household_head.name', 'Chủ hộ pháp lý');
        $this->assertDatabaseHas('residence_declarations', ['id' => $id, 'landlord_id' => $room->boardingHouse->landlord_id, 'status' => 'DRAFT', 'snapshot' => null]);
        $this->assertSame('Nguyễn Văn A', $tenant->fresh()->full_name);
    }

    public function test_incomplete_draft_saves_but_export_reports_missing_fields(): void
    {
        [$user, $room, $tenant] = $this->fixture();
        $tenant->update(['identity_number' => null]);
        $room->boardingHouse->residenceProfile->update(['household_head_name' => null]);
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/residence-declarations', ['room_id' => $room->id, 'declarant_tenant_id' => $tenant->id])->assertCreated()->json('data.id');
        $response = $this->getJson("/api/residence-declarations/{$id}/export")->assertUnprocessable()
            ->assertJsonValidationErrors(['declarant.identity_number', 'household_head.name', 'authority_name']);
        $this->assertSame('Số định danh cá nhân của Nguyễn Văn A chưa được cập nhật.', $response->json('errors')['declarant.identity_number'][0]);
        $this->assertDatabaseHas('residence_declarations', ['id' => $id, 'status' => 'DRAFT', 'snapshot' => null]);
    }

    public function test_moved_out_foreign_and_duplicate_members_are_rejected_without_records(): void
    {
        [$user, $room, $tenant, $member] = $this->fixture();
        $moved = $this->person($room, 'Đã chuyển đi', 'MOVED_OUT');
        $this->actingAs($user, 'sanctum')->postJson('/api/residence-declarations', $this->payload($room, $moved, $member))
            ->assertUnprocessable()->assertJsonValidationErrors('declarant_tenant_id');
        [, $foreignRoom, $foreignTenant] = $this->fixture();
        $this->postJson('/api/residence-declarations', [...$this->payload($room, $tenant, $member), 'members' => [['tenant_id' => $foreignTenant->id]]])
            ->assertUnprocessable()->assertJsonValidationErrors('members.0.tenant_id');
        $this->postJson('/api/residence-declarations', [...$this->payload($room, $tenant, $member), 'members' => [['tenant_id' => $moved->id]]])
            ->assertUnprocessable()->assertJsonValidationErrors('members.0.tenant_id');
        $this->postJson('/api/residence-declarations', [...$this->payload($room, $tenant, $member), 'members' => [['tenant_id' => $member->id], ['tenant_id' => $member->id]]])
            ->assertUnprocessable()->assertJsonValidationErrors('members.0.tenant_id');
        $this->assertDatabaseCount('residence_declarations', 0);
        $this->assertDatabaseCount('residence_declaration_members', 0);
    }

    public function test_submitted_and_registered_records_keep_snapshot_when_master_data_changes(): void
    {
        [$user, $room, $tenant, $member] = $this->fixture();
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/residence-declarations', $this->payload($room, $tenant, $member))->json('data.id');
        $this->putJson("/api/residence-declarations/{$id}/status", ['status' => 'SUBMITTED'])->assertOk();
        $snapshot = ResidenceDeclaration::findOrFail($id)->snapshot;
        $tenant->update(['full_name' => 'Tên mới', 'identity_number' => '999999999999', 'email' => 'new@example.com']);
        $member->update(['date_of_birth' => '2001-01-01']);
        $room->boardingHouse->residenceProfile->update(['household_head_name' => 'Chủ hộ mới']);
        RoomTenant::where('tenant_id', $tenant->id)->update(['status' => 'MOVED_OUT']);

        $this->getJson("/api/residence-declarations/{$id}/export")->assertOk()
            ->assertJsonPath('data.ct01.declarant.full_name', 'Nguyễn Văn A')
            ->assertJsonPath('data.ct01.declarant.identity_number', '079000000001')
            ->assertJsonPath('data.ct01.household_head.name', 'Chủ hộ pháp lý');
        $this->putJson("/api/residence-declarations/{$id}/status", ['status' => 'REGISTERED', 'expires_at' => '2027-12-31'])->assertOk();
        $this->assertSame($snapshot, ResidenceDeclaration::findOrFail($id)->snapshot);
        $this->getJson('/api/residence-declarations')->assertOk()->assertJsonPath('data.0.declarant_name', 'Nguyễn Văn A');
        $this->putJson("/api/residence-declarations/{$id}", $this->payload($room, $tenant, $member))->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->deleteJson("/api/residence-declarations/{$id}")->assertUnprocessable();
    }

    public function test_draft_update_replaces_members_and_delete_removes_only_draft(): void
    {
        [$user, $room, $tenant, $member] = $this->fixture();
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/residence-declarations', $this->payload($room, $tenant, $member))->json('data.id');
        $this->putJson("/api/residence-declarations/{$id}", [...$this->payload($room, $tenant, $member), 'members' => []])->assertOk()->assertJsonPath('data', null);
        $this->assertDatabaseCount('residence_declaration_members', 0);
        $this->deleteJson("/api/residence-declarations/{$id}")->assertOk();
        $this->assertDatabaseMissing('residence_declarations', ['id' => $id]);
        $this->assertModelExists($tenant);
    }

    public function test_owner_and_assigned_manager_are_scoped_and_revocation_takes_effect(): void
    {
        [$owner, $room, $tenant, $member] = $this->fixture();
        [$foreign, $foreignRoom] = $this->fixture();
        $manager = User::factory()->create();
        $room->boardingHouse->managers()->attach($manager->id);
        $id = $this->actingAs($manager, 'sanctum')->postJson('/api/residence-declarations', $this->payload($room, $tenant, $member))->assertCreated()->json('data.id');
        $this->assertDatabaseHas('residence_declarations', ['id' => $id, 'landlord_id' => $room->boardingHouse->landlord_id]);
        $this->getJson('/api/residence/boarding-houses')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.operational_role', 'MANAGER');
        $this->getJson("/api/residence/rooms/{$foreignRoom->id}/context")->assertNotFound();
        $this->actingAs($foreign, 'sanctum')->getJson("/api/residence-declarations/{$id}")->assertNotFound();
        $this->actingAs($owner, 'sanctum')->getJson("/api/residence-declarations/{$id}")->assertOk();
        $room->boardingHouse->managers()->detach($manager->id);
        $this->actingAs($manager, 'sanctum')->getJson("/api/residence-declarations/{$id}")->assertNotFound();
        $this->putJson("/api/residence-declarations/{$id}", $this->payload($room, $tenant, $member))->assertNotFound();
    }

    public function test_foreign_room_and_invalid_state_do_not_create_or_change_snapshot(): void
    {
        [$user, $room, $tenant, $member] = $this->fixture();
        [$foreign, $foreignRoom] = $this->fixture();
        $this->actingAs($foreign, 'sanctum')->postJson('/api/residence-declarations', $this->payload($room, $tenant, $member))->assertNotFound();
        $this->assertDatabaseCount('residence_declarations', 0);
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/residence-declarations', $this->payload($room, $tenant, $member))->json('data.id');
        $this->putJson("/api/residence-declarations/{$id}/status", ['status' => 'REGISTERED'])->assertUnprocessable();
        $this->assertDatabaseHas('residence_declarations', ['id' => $id, 'status' => 'DRAFT', 'snapshot' => null]);
    }

    private function payload(Room $room, Tenant $tenant, Tenant $member): array
    {
        return ['room_id' => $room->id, 'declarant_tenant_id' => $tenant->id, 'authority_name' => 'Công an phường', 'relationship_to_household_head' => 'Người thuê', 'request_content' => 'Đăng ký tạm trú tại địa chỉ nhà trọ.', 'members' => [['tenant_id' => $member->id, 'relationship_to_household_head' => 'Người thuê']]];
    }

    public function test_zero_optional_ids_return_422_instead_of_a_foreign_key_server_error(): void
    {
        [$user, $room] = $this->fixture();
        $this->actingAs($user, 'sanctum')->postJson('/api/residence-declarations', ['room_id' => $room->id, 'contract_id' => 0, 'declarant_tenant_id' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors(['contract_id', 'declarant_tenant_id']);
        $this->assertDatabaseCount('residence_declarations', 0);
    }

    private function fixture(): array
    {
        $user = User::factory()->create();
        $landlord = Landlord::create(['user_id' => $user->id, 'full_name' => $user->name, 'phone' => '0900000000']);
        $house = BoardingHouse::create(['landlord_id' => $landlord->id, 'name' => 'BeeHouse', 'address' => 'Địa chỉ nhà trọ']);
        $room = Room::create(['boarding_house_id' => $house->id, 'room_code' => '101', 'status' => 'AVAILABLE']);
        BoardingHouseResidenceProfile::factory()->for($house)->create(['household_head_name' => 'Chủ hộ pháp lý', 'household_head_identity_number' => '079000000099']);

        return [$user, $room, $this->person($room, 'Nguyễn Văn A'), $this->person($room, 'Trần Thị B')];
    }

    private function person(Room $room, string $name, string $status = 'ACTIVE'): Tenant
    {
        $tenant = Tenant::create(['landlord_id' => $room->boardingHouse->landlord_id, 'full_name' => $name, 'date_of_birth' => '2000-09-28', 'gender' => 'MALE', 'identity_number' => '079000000001']);
        RoomTenant::create(['room_id' => $room->id, 'tenant_id' => $tenant->id, 'status' => $status, 'move_in_date' => '2026-10-01']);

        return $tenant;
    }
}
