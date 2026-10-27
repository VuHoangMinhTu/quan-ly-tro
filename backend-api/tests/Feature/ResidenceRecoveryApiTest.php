<?php

namespace Tests\Feature;

use App\Models\BoardingHouse;
use App\Models\Landlord;
use App\Models\Room;
use App\Models\RoomTenant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResidenceRecoveryApiTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        $owner = User::factory()->create(['name' => 'Tài khoản quản lý ứng dụng']);
        $landlord = Landlord::create(['user_id' => $owner->id, 'full_name' => $owner->name, 'phone' => '0900000000']);
        $house = BoardingHouse::create(['landlord_id' => $landlord->id, 'name' => 'Nhà trọ kiểm thử', 'address' => 'Địa chỉ kiểm thử', 'ward' => 'Phường Kiểm Thử']);
        $room = Room::create(['boarding_house_id' => $house->id, 'room_code' => '101', 'status' => 'RENTED']);
        $tenant = Tenant::create(['landlord_id' => $landlord->id, 'full_name' => 'Người kê khai kiểm thử', 'date_of_birth' => '2000-01-01', 'gender' => 'MALE', 'identity_number' => '000000000001', 'phone' => '0900000001', 'permanent_address' => 'Địa chỉ thường trú kiểm thử']);
        RoomTenant::create(['room_id' => $room->id, 'tenant_id' => $tenant->id, 'status' => 'ACTIVE', 'move_in_date' => '2026-10-01']);
        $this->actingAs($owner, 'sanctum');

        return [$owner, $house, $room, $tenant];
    }

    public function test_users_only_database_reads_do_not_seed_fictitious_workspaces_or_residence_records(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'sanctum');
        $before = $owner->fresh()->getAttributes();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->getJson('/api/residence/boarding-houses')->assertOk()->assertJsonCount(0, 'data');
            $this->getJson('/api/residence-declarations')->assertOk()->assertJsonCount(0, 'data');
        }
        $this->assertDatabaseCount('landlords', 0);
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('boarding_house_residence_profiles', 0);
        $this->assertDatabaseCount('residence_declarations', 0);
        $this->assertDatabaseCount('residence_declaration_members', 0);
        $this->assertSame($before, $owner->fresh()->getAttributes());
    }

    public function test_absent_configuration_and_contract_templates_do_not_break_draft_reload_or_invent_legal_owner(): void
    {
        [$owner, $house, $room, $tenant] = $this->workspace();
        DB::table('contract_templates')->delete();
        $this->getJson("/api/boarding-houses/{$house->id}/residence-profile")->assertOk()->assertJsonPath('data', null);
        $this->getJson("/api/residence/rooms/{$room->id}/context")->assertOk()
            ->assertJsonPath('data.room.boarding_house.residence_profile', null)
            ->assertJsonPath('data.suggested_declaration.request_content', 'Đăng ký tạm trú')
            ->assertJsonPath('data.suggested_declaration.authority_name', null);
        $id = $this->postJson('/api/residence-declarations', ['room_id' => $room->id, 'declarant_tenant_id' => $tenant->id, 'household_head_mode' => 'DECLARANT', 'stay_start_date' => '2026-10-01', 'stay_end_date' => '2027-09-30'])->assertCreated()->json('data.id');
        $this->getJson("/api/residence-declarations/{$id}")->assertOk()
            ->assertJsonPath('data.declaration.status', 'DRAFT')->assertJsonPath('data.ct01.household_head.name', $tenant->full_name)
            ->assertJsonPath('data.stay_consent.host.full_name', null)->assertJsonPath('data.stay_consent.host.identity_number', null)
            ->assertJsonPath('data.stay_consent.guest.full_name', $tenant->full_name);
        $errors = $this->getJson("/api/residence-declarations/{$id}/documents/residence-stay-consent")->assertUnprocessable()
            ->assertJsonValidationErrors(['host.full_name', 'host.birth_date'])->json('errors');
        $this->assertSame('Vui lòng bổ sung họ tên Bên A trước khi in văn bản.', $errors['host.full_name'][0]);
        $this->assertSame('Vui lòng bổ sung ngày sinh Bên A trước khi in văn bản.', $errors['host.birth_date'][0]);
        $this->putJson("/api/residence-declarations/{$id}/status", ['status' => 'SUBMITTED'])->assertUnprocessable()->assertJsonValidationErrors('authority_name');
        $this->assertDatabaseHas('residence_declarations', ['id' => $id, 'status' => 'DRAFT', 'snapshot' => null]);
        $this->assertDatabaseCount('boarding_house_residence_profiles', 0);
        $this->assertSame('Tài khoản quản lý ứng dụng', $owner->fresh()->name);
    }

    public function test_explicit_profile_setup_is_idempotent_and_both_documents_use_user_provided_data(): void
    {
        [$owner, $house, $room, $tenant] = $this->workspace();
        $profile = ['residence_authority_name' => 'Cơ quan tiếp nhận kiểm thử', 'legal_owner_name' => 'Bên A đã được xác nhận', 'legal_owner_birth_date' => '1970-01-01', 'legal_owner_permanent_address' => 'Địa chỉ Bên A kiểm thử', 'legal_owner_identity_number' => '000000000002', 'legal_owner_phone' => '0900000002'];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->putJson("/api/boarding-houses/{$house->id}/residence-profile", $profile)->assertOk();
        }
        $this->assertDatabaseCount('boarding_house_residence_profiles', 1);
        $id = $this->postJson('/api/residence-declarations', ['room_id' => $room->id, 'declarant_tenant_id' => $tenant->id, 'household_head_mode' => 'OTHER_PERSON', 'household_head_name' => 'Chủ hộ do người dùng nhập', 'household_head_identity_number' => '000000000003', 'relationship_to_household_head' => 'Ở nhờ', 'stay_start_date' => '2026-10-01', 'stay_end_date' => '2027-09-30'])->assertCreated()->json('data.id');
        $this->getJson("/api/residence-declarations/{$id}/export")->assertOk()->assertJsonPath('data.ct01.household_head.name', 'Chủ hộ do người dùng nhập');
        $this->getJson("/api/residence-declarations/{$id}/documents/residence-stay-consent")->assertOk()->assertJsonPath('data.document.host.full_name', $profile['legal_owner_name']);
        $this->putJson("/api/residence-declarations/{$id}/status", ['status' => 'SUBMITTED'])->assertOk();
        $this->putJson("/api/boarding-houses/{$house->id}/residence-profile", [...$profile, 'legal_owner_name' => 'Tên cập nhật sau khi chốt'])->assertOk();
        $this->getJson("/api/residence-declarations/{$id}")->assertOk()->assertJsonPath('data.is_frozen', true)->assertJsonPath('data.stay_consent.host.full_name', $profile['legal_owner_name']);
        $this->assertSame('Tài khoản quản lý ứng dụng', $owner->fresh()->name);
        $this->assertSame('Người kê khai kiểm thử', $tenant->fresh()->full_name);
    }
}
