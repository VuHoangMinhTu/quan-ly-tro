<?php

namespace Tests\Feature;

use App\Models\BoardingHouse;
use App\Models\Landlord;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FloorDormitoryApiTest extends TestCase
{
    use RefreshDatabase;

    private function house(): BoardingHouse
    {
        $user = User::factory()->create();
        $landlord = Landlord::create(['user_id' => $user->id, 'full_name' => $user->name, 'phone' => '+84901111111']);
        $this->actingAs($user, 'sanctum');

        return BoardingHouse::create(['landlord_id' => $landlord->id, 'name' => 'Nhà A', 'address' => 'Địa chỉ A']);
    }

    public function test_floor_and_bulk_room_are_owned_and_atomic(): void
    {
        $house = $this->house();
        $floorId = $this->postJson("/api/boarding-houses/{$house->id}/floors", ['name' => 'Tầng 2'])->assertCreated()->json('data.id');
        $this->postJson("/api/boarding-houses/{$house->id}/rooms/bulk", [
            'floor_id' => $floorId,
            'rooms' => [['room_code' => '201', 'monthly_rent' => null], ['room_code' => '202', 'monthly_rent' => 4000000]],
        ])->assertCreated();
        $this->assertDatabaseHas('rooms', ['boarding_house_id' => $house->id, 'floor_id' => $floorId, 'room_code' => '201', 'monthly_rent' => null, 'room_type' => 'PRIVATE_ROOM']);
        $this->postJson("/api/boarding-houses/{$house->id}/rooms/bulk", ['floor_id' => $floorId, 'rooms' => [['room_code' => '203'], ['room_code' => '201']]])
            ->assertUnprocessable()->assertJsonValidationErrors('rooms.1.room_code');
        $this->assertDatabaseMissing('rooms', ['boarding_house_id' => $house->id, 'room_code' => '203']);
    }

    public function test_dorm_room_creates_distinct_beds_with_individual_prices(): void
    {
        $house = $this->house();
        $payload = ['room_code' => 'KTX-201', 'room_type' => 'DORMITORY', 'status' => 'AVAILABLE', 'beds' => [
            ['bed_code' => 'G01', 'monthly_rent' => 1500000],
            ['bed_code' => 'G02', 'monthly_rent' => 1700000],
            ['bed_code' => 'G03', 'monthly_rent' => null],
        ]];
        $this->postJson("/api/boarding-houses/{$house->id}/rooms", $payload)->assertCreated()->assertJsonCount(3, 'data.beds');
        $this->assertDatabaseHas('beds', ['bed_code' => 'G03', 'monthly_rent' => null]);
        $this->postJson("/api/boarding-houses/{$house->id}/rooms", [...$payload, 'room_code' => 'KTX-202', 'beds' => [['bed_code' => 'G01'], ['bed_code' => 'G01']]])
            ->assertUnprocessable()->assertJsonValidationErrors('beds.1.bed_code');
        $this->assertDatabaseMissing('rooms', ['room_code' => 'KTX-202']);
    }

    public function test_legacy_room_defaults_to_private_without_floor(): void
    {
        $house = $this->house();
        $room = $house->rooms()->create(['room_code' => '001', 'status' => 'AVAILABLE', 'monthly_rent' => null]);
        $this->getJson("/api/rooms/{$room->id}")->assertOk()->assertJsonPath('data.room_type', 'PRIVATE_ROOM')->assertJsonPath('data.floor_id', null);
    }

    public function test_foreign_floor_is_rejected(): void
    {
        $other = $this->house();
        $floorId = $this->postJson("/api/boarding-houses/{$other->id}/floors", ['name' => 'Tầng 1'])->assertCreated()->json('data.id');
        $house = $this->house();
        $this->postJson("/api/boarding-houses/{$house->id}/rooms/bulk", ['floor_id' => $floorId, 'rooms' => [['room_code' => '101']]])->assertUnprocessable()->assertJsonValidationErrors('floor_id');
        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_other_landlord_cannot_list_or_create_floors(): void
    {
        $house = $this->house();
        $this->house();
        $this->getJson("/api/boarding-houses/{$house->id}/floors")->assertNotFound();
        $this->postJson("/api/boarding-houses/{$house->id}/floors", ['name' => 'Tầng lạ'])->assertNotFound();
        $this->assertDatabaseCount('floors', 0);
    }

    public function test_dorm_occupancy_requires_a_free_bed_in_the_same_room(): void
    {
        $house = $this->house();
        $room = $house->rooms()->create(['room_code' => 'KTX-1', 'room_type' => 'DORMITORY', 'status' => 'AVAILABLE']);
        $otherRoom = $house->rooms()->create(['room_code' => 'KTX-2', 'room_type' => 'DORMITORY', 'status' => 'AVAILABLE']);
        $bed = $room->beds()->create(['bed_code' => 'G01']);
        $otherBed = $otherRoom->beds()->create(['bed_code' => 'G01']);
        $tenant = Tenant::create(['landlord_id' => $house->landlord_id, 'full_name' => 'Khách A']);
        $payload = ['tenant_id' => $tenant->id, 'move_in_date' => '2026-10-22', 'status' => 'ACTIVE'];

        $this->postJson("/api/rooms/{$room->id}/tenants", $payload)->assertUnprocessable()->assertJsonValidationErrors('bed_id');
        $this->postJson("/api/rooms/{$room->id}/tenants", [...$payload, 'bed_id' => $otherBed->id])->assertUnprocessable()->assertJsonValidationErrors('bed_id');
        $this->postJson("/api/rooms/{$room->id}/tenants", [...$payload, 'bed_id' => $bed->id])->assertCreated();
        $this->assertDatabaseHas('beds', ['id' => $bed->id, 'status' => 'OCCUPIED']);
        $second = Tenant::create(['landlord_id' => $house->landlord_id, 'full_name' => 'Khách B']);
        $this->postJson("/api/rooms/{$room->id}/tenants", [...$payload, 'tenant_id' => $second->id, 'bed_id' => $bed->id])->assertUnprocessable()->assertJsonValidationErrors('bed_id');
        $this->assertDatabaseHas('room_tenants', ['room_id' => $room->id, 'tenant_id' => $tenant->id, 'bed_id' => $bed->id]);
        $membershipId = $room->roomTenants()->where('tenant_id', $tenant->id)->value('id');
        $this->putJson("/api/room-tenants/{$membershipId}", [...$payload, 'bed_id' => $bed->id, 'status' => 'MOVED_OUT', 'move_out_date' => '2026-10-23'])->assertOk();
        $this->assertDatabaseHas('beds', ['id' => $bed->id, 'status' => 'AVAILABLE']);
    }
}
