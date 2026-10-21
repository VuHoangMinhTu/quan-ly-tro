<?php

namespace Tests\Feature;

use App\Models\BoardingHouse;
use App\Models\BoardingHouseResidenceProfile;
use App\Models\Contract;
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
        $this->getJson('/api/residence-declarations/1/documents/residence-stay-consent')->assertUnauthorized();
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
            ->assertJsonPath('data.ct01.household_head.name', 'Nguyễn Văn A');
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
            ->assertJsonValidationErrors(['declarant.identity_number', 'household_head_mode', 'household_head.name', 'authority_name']);
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
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/residence-declarations', [...$this->payload($room, $tenant, $member), 'household_head_opinion' => 'Ý kiến đã ghi nhận'])->json('data.id');
        $this->putJson("/api/residence-declarations/{$id}/status", ['status' => 'SUBMITTED'])->assertOk();
        $snapshot = ResidenceDeclaration::findOrFail($id)->snapshot;
        $tenant->update(['full_name' => 'Tên mới', 'identity_number' => '999999999999', 'email' => 'new@example.com']);
        $member->update(['date_of_birth' => '2001-01-01']);
        $room->boardingHouse->residenceProfile->update(['household_head_name' => 'Chủ hộ mới']);
        ResidenceDeclaration::findOrFail($id)->update(['household_head_opinion' => 'Ý kiến sửa sau khi chốt']);
        RoomTenant::where('tenant_id', $tenant->id)->update(['status' => 'MOVED_OUT']);

        $this->getJson("/api/residence-declarations/{$id}/export")->assertOk()
            ->assertJsonPath('data.ct01.declarant.full_name', 'Nguyễn Văn A')
            ->assertJsonPath('data.ct01.declarant.identity_number', '079000000001')
            ->assertJsonPath('data.ct01.household_head.name', 'Nguyễn Văn A')
            ->assertJsonPath('data.ct01.opinions.household_head', 'Ý kiến đã ghi nhận');
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
        return ['room_id' => $room->id, 'declarant_tenant_id' => $tenant->id, 'authority_name' => 'Công an phường', 'household_head_mode' => 'DECLARANT', 'request_content' => 'Đăng ký tạm trú tại địa chỉ nhà trọ.', 'members' => [['tenant_id' => $member->id, 'relationship_to_household_head' => 'Con']]];
    }

    public function test_household_head_is_chosen_per_declaration_and_not_copied_from_legal_owner(): void
    {
        [$user, $room, $tenant, $member] = $this->fixture();
        $room->boardingHouse->residenceProfile->update(['legal_owner_name' => 'Chủ sở hữu khác', 'legal_owner_identity_number' => '079999999999']);
        $payload = [...$this->payload($room, $tenant, $member), 'household_head_mode' => 'OTHER_PERSON',
            'household_head_name' => 'Chủ hộ riêng', 'household_head_identity_number' => '079888888888',
            'relationship_to_household_head' => 'Ở thuê'];
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/residence-declarations', $payload)->assertCreated()->json('data.id');

        $this->getJson("/api/residence-declarations/{$id}")->assertOk()
            ->assertJsonPath('data.ct01.household_head.name', 'Chủ hộ riêng')
            ->assertJsonPath('data.ct01.household_head.identity_number', '079888888888')
            ->assertJsonPath('data.ct01.relationship_to_household_head', 'Ở thuê')
            ->assertJsonPath('data.ct01.members.0.relationship_to_household_head', 'Con')
            ->assertJsonPath('data.stay_consent.host.full_name', 'Chủ sở hữu khác');

        $this->putJson("/api/residence-declarations/{$id}/status", ['status' => 'SUBMITTED'])->assertOk();
        $tenant->update(['full_name' => 'Tên mới']);
        $member->update(['full_name' => 'Thành viên mới']);
        $room->boardingHouse->residenceProfile->update(['legal_owner_name' => 'Chủ sở hữu mới']);
        $this->getJson("/api/residence-declarations/{$id}")->assertOk()
            ->assertJsonPath('data.ct01.household_head.name', 'Chủ hộ riêng')
            ->assertJsonPath('data.ct01.declarant.full_name', 'Nguyễn Văn A')
            ->assertJsonPath('data.ct01.members.0.full_name', 'Trần Thị B')
            ->assertJsonPath('data.stay_consent.host.full_name', 'Chủ sở hữu khác');
    }

    public function test_legacy_draft_without_household_head_choice_does_not_guess_from_profile(): void
    {
        [$user, $room, $tenant] = $this->fixture();
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/residence-declarations', [
            'room_id' => $room->id, 'declarant_tenant_id' => $tenant->id,
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/residence-declarations/{$id}")->assertOk()
            ->assertJsonPath('data.ct01.household_head.name', null)
            ->assertJsonPath('data.ct01.household_head.identity_number', null)
            ->assertJsonFragment(['field' => 'household_head_mode', 'message' => 'Vui lòng xác nhận ai là chủ hộ của hồ sơ cư trú.', 'target' => 'declaration']);
    }

    public function test_zero_optional_ids_return_422_instead_of_a_foreign_key_server_error(): void
    {
        [$user, $room] = $this->fixture();
        $this->actingAs($user, 'sanctum')->postJson('/api/residence-declarations', ['room_id' => $room->id, 'contract_id' => 0, 'declarant_tenant_id' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors(['contract_id', 'declarant_tenant_id']);
        $this->assertDatabaseCount('residence_declarations', 0);
    }

    public function test_ct01_context_prefills_authority_and_only_active_selected_members(): void
    {
        [$owner, $room, $tenant, $member] = $this->fixture();
        $room->boardingHouse->residenceProfile->update(['residence_authority_name' => 'Công an phường đã cấu hình']);
        $moved = $this->person($room, 'Người đã chuyển đi', 'MOVED_OUT');
        $contract = Contract::factory()->create(['room_id' => $room->id, 'tenant_id' => $tenant->id]);

        $this->actingAs($owner, 'sanctum')->getJson("/api/residence/rooms/{$room->id}/context?contract_id={$contract->id}")
            ->assertOk()->assertJsonPath('data.suggested_declaration.authority_name', 'Công an phường đã cấu hình')
            ->assertJsonPath('data.suggested_declaration.declarant_tenant_id', $tenant->id)
            ->assertJsonCount(2, 'data.occupants')->assertJsonMissing(['full_name' => $moved->full_name]);
        $id = $this->postJson('/api/residence-declarations', ['room_id' => $room->id, 'declarant_tenant_id' => $tenant->id])->assertCreated()->json('data.id');
        $this->getJson("/api/residence-declarations/{$id}")->assertJsonPath('data.ct01.authority_name', 'Công an phường đã cấu hình');
        $this->assertModelExists($member);
    }

    public function test_residence_profile_saves_legal_owner_fields_without_using_operational_account(): void
    {
        [$owner, $room] = $this->fixture();

        $this->actingAs($owner, 'sanctum')->putJson("/api/boarding-houses/{$room->boarding_house_id}/residence-profile", [
            'household_head_name' => 'Chủ hộ', 'household_head_identity_number' => '079000000099',
            'residence_authority_name' => 'Công an phường đã cấu hình', 'legal_owner_name' => 'Chủ sở hữu',
            'legal_owner_birth_year' => 1970, 'legal_owner_permanent_address' => 'Địa chỉ chủ sở hữu',
            'legal_owner_identity_number' => '079111111111', 'legal_owner_phone' => '0902222222',
        ])->assertOk()->assertJsonPath('data', null);

        $this->getJson("/api/boarding-houses/{$room->boarding_house_id}/residence-profile")->assertOk()
            ->assertJsonPath('data.legal_owner_name', 'Chủ sở hữu')
            ->assertJsonPath('data.residence_authority_name', 'Công an phường đã cấu hình');
        $this->assertDatabaseHas('boarding_house_residence_profiles', ['boarding_house_id' => $room->boarding_house_id, 'legal_owner_identity_number' => '079111111111']);
        $this->assertNotSame($owner->name, $room->boardingHouse->residenceProfile->fresh()->legal_owner_name);
    }

    public function test_stay_consent_prefills_profile_tenant_contract_and_selected_active_members_then_freezes(): void
    {
        [$owner, $room, $tenant, $member] = $this->fixture();
        $owner->update(['name' => 'Tên tài khoản vận hành']);
        $tenant->update(['phone' => '0901111111', 'permanent_address' => 'Địa chỉ Bên B', 'email' => 'old@example.com']);
        $room->boardingHouse->residenceProfile->update([
            'legal_owner_name' => 'Chủ sở hữu pháp lý', 'legal_owner_birth_year' => 1970,
            'legal_owner_permanent_address' => 'Địa chỉ Bên A', 'legal_owner_identity_number' => '079111111111', 'legal_owner_phone' => '0902222222',
        ]);
        $contract = Contract::factory()->create(['room_id' => $room->id, 'tenant_id' => $tenant->id, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31']);
        $payload = [...$this->payload($room, $tenant, $member), 'contract_id' => $contract->id];
        $id = $this->actingAs($owner, 'sanctum')->postJson('/api/residence-declarations', $payload)->assertCreated()->json('data.id');

        $this->getJson("/api/residence-declarations/{$id}")->assertOk()
            ->assertJsonPath('data.stay_consent.type', 'RESIDENCE_STAY_CONSENT')
            ->assertJsonPath('data.stay_consent.host.full_name', 'Chủ sở hữu pháp lý')
            ->assertJsonPath('data.stay_consent.guest.full_name', 'Nguyễn Văn A')
            ->assertJsonPath('data.stay_consent.start_date', '2026-11-01')
            ->assertJsonPath('data.stay_consent.companions.0.full_name', 'Trần Thị B');
        $this->getJson("/api/residence-declarations/{$id}/documents/residence-stay-consent")->assertOk()
            ->assertJsonPath('data.document.occupant_count', 2);
        $this->putJson("/api/residence-declarations/{$id}/status", ['status' => 'SUBMITTED'])->assertOk();
        $snapshot = ResidenceDeclaration::findOrFail($id)->snapshot;
        $tenant->update(['full_name' => 'Tên mới', 'phone' => '0909999999', 'email' => 'new@example.com']);
        $room->boardingHouse->residenceProfile->update(['legal_owner_name' => 'Chủ mới']);
        $contract->update(['start_date' => '2028-01-01']);

        $this->getJson("/api/residence-declarations/{$id}/documents/residence-stay-consent")->assertOk()
            ->assertJsonPath('data.document.host.full_name', 'Chủ sở hữu pháp lý')
            ->assertJsonPath('data.document.guest.full_name', 'Nguyễn Văn A')
            ->assertJsonPath('data.document.guest.phone', '0901111111')
            ->assertJsonPath('data.document.start_date', '2026-11-01')
            ->assertJsonPath('data.is_frozen', true);
        $this->assertSame($snapshot, ResidenceDeclaration::findOrFail($id)->snapshot);
        $this->assertNotSame('Tên tài khoản vận hành', $snapshot['stay_consent']['host']['full_name']);
    }

    public function test_stay_consent_print_requires_complete_data_and_foreign_house_is_hidden(): void
    {
        [$owner, $room, $tenant, $member] = $this->fixture();
        $id = $this->actingAs($owner, 'sanctum')->postJson('/api/residence-declarations', $this->payload($room, $tenant, $member))->assertCreated()->json('data.id');

        $this->getJson("/api/residence-declarations/{$id}")->assertOk()->assertJsonPath('data.stay_consent.host.full_name', null);
        $this->getJson("/api/residence-declarations/{$id}/documents/residence-stay-consent")
            ->assertUnprocessable()->assertJsonValidationErrors(['host.full_name', 'host.birth_year', 'guest.phone', 'start_date']);
        [$foreign] = $this->fixture();
        $this->actingAs($foreign, 'sanctum')->getJson("/api/residence-declarations/{$id}/documents/residence-stay-consent")->assertNotFound();
    }

    public function test_assigned_manager_can_preview_stay_consent_but_revoked_manager_cannot(): void
    {
        [$owner, $room, $tenant, $member] = $this->fixture();
        $manager = User::factory()->create();
        $room->boardingHouse->managers()->attach($manager);
        $id = $this->actingAs($owner, 'sanctum')->postJson('/api/residence-declarations', $this->payload($room, $tenant, $member))->assertCreated()->json('data.id');

        $this->actingAs($manager, 'sanctum')->getJson("/api/residence-declarations/{$id}")->assertOk()->assertJsonPath('data.stay_consent.type', 'RESIDENCE_STAY_CONSENT');
        $room->boardingHouse->managers()->detach($manager);
        $this->getJson("/api/residence-declarations/{$id}")->assertNotFound();
    }

    public function test_stay_consent_can_use_manual_dates_without_contract_and_old_snapshots_are_not_rebuilt(): void
    {
        [$owner, $room, $tenant, $member] = $this->fixture();
        $tenant->update(['phone' => '0901111111', 'permanent_address' => 'Địa chỉ Bên B']);
        $room->boardingHouse->residenceProfile->update([
            'legal_owner_name' => 'Bên A', 'legal_owner_birth_year' => 1970, 'legal_owner_permanent_address' => 'Địa chỉ Bên A',
            'legal_owner_identity_number' => '079111111111', 'legal_owner_phone' => '0902222222',
        ]);
        $payload = [...$this->payload($room, $tenant, $member), 'stay_start_date' => '2026-11-15', 'stay_end_date' => '2027-11-14'];
        $id = $this->actingAs($owner, 'sanctum')->postJson('/api/residence-declarations', $payload)->assertCreated()->json('data.id');

        $this->getJson("/api/residence-declarations/{$id}/documents/residence-stay-consent")
            ->assertOk()->assertJsonPath('data.document.start_date', '2026-11-15')->assertJsonPath('data.document.end_date', '2027-11-14');
        $this->putJson("/api/residence-declarations/{$id}", [...$payload, 'stay_end_date' => '2026-10-01'])->assertUnprocessable()->assertJsonValidationErrors('stay_end_date');
        $this->putJson("/api/residence-declarations/{$id}/status", ['status' => 'SUBMITTED'])->assertOk();
        $record = ResidenceDeclaration::findOrFail($id);
        $oldSnapshot = $record->snapshot;
        unset($oldSnapshot['stay_consent']);
        $record->update(['snapshot' => $oldSnapshot]);

        $this->getJson("/api/residence-declarations/{$id}")->assertOk()->assertJsonPath('data.stay_consent', null);
        $this->getJson("/api/residence-declarations/{$id}/documents/residence-stay-consent")->assertUnprocessable()->assertJsonValidationErrors('document');
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
