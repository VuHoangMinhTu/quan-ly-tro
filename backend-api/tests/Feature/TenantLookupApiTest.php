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

class TenantLookupApiTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        $user = User::factory()->create();
        $landlord = Landlord::create(['user_id' => $user->id, 'full_name' => $user->name, 'phone' => '0900000000']);
        $house = BoardingHouse::create(['landlord_id' => $landlord->id, 'name' => 'Nhà thử', 'address' => 'Địa chỉ thử']);
        $room = Room::create(['boarding_house_id' => $house->id, 'room_code' => '101', 'status' => 'AVAILABLE', 'max_tenants' => 100]);

        return [$user, $landlord, $house, $room];
    }

    private function tenant(Landlord $landlord, string $name = 'Nguyen An'): Tenant
    {
        return Tenant::create(['landlord_id' => $landlord->id, 'full_name' => $name, 'phone' => '0987654321', 'identity_number' => '079123456789']);
    }

    private function stay(Tenant $tenant, Room $room, string $status = 'ACTIVE'): void
    {
        RoomTenant::create(['tenant_id' => $tenant->id, 'room_id' => $room->id, 'move_in_date' => '2026-01-01', 'move_out_date' => $status === 'MOVED_OUT' ? '2026-02-01' : null, 'status' => $status, 'is_primary' => false]);
    }

    public function test_lookup_is_scoped_paginated_and_does_not_expose_identity(): void
    {
        [$user, $landlord] = $this->workspace();
        [, $foreign] = $this->workspace();
        $this->tenant($foreign, 'Foreign');
        for ($i = 0; $i < 23; $i++) {
            $this->tenant($landlord, sprintf('Nguyen %02d', $i));
        }
        $this->getJson('/api/tenant-lookup')->assertUnauthorized();
        $this->actingAs($user, 'sanctum')->getJson('/api/tenant-lookup')->assertOk()->assertJsonCount(20, 'data.tenants')->assertJsonPath('data.total', 23)->assertJsonPath('data.last_page', 2)->assertJsonMissingPath('data.tenants.0.identity_number');
        $this->getJson('/api/tenant-lookup?page=2')->assertOk()->assertJsonCount(3, 'data.tenants');
        foreach (['nguyen', '098765', '079123'] as $search) {
            $this->getJson('/api/tenant-lookup?search='.$search)->assertOk()->assertJsonPath('data.total', 23);
        }
        $this->getJson('/api/tenant-lookup?per_page=101')->assertUnprocessable();
    }

    public function test_current_occupancy_filters_and_active_room_scope_cannot_be_bypassed(): void
    {
        [$user, $landlord, $house, $room] = $this->workspace();
        $other = Room::create(['boarding_house_id' => $house->id, 'room_code' => '102', 'status' => 'AVAILABLE']);
        $active = $this->tenant($landlord, 'Active');
        $moved = $this->tenant($landlord, 'Moved');
        $new = $this->tenant($landlord, 'New');
        $this->stay($active, $other, 'MOVED_OUT');
        $this->stay($active, $room);
        $this->stay($moved, $other, 'MOVED_OUT');
        $this->actingAs($user, 'sanctum');
        foreach (['ACTIVE' => $active, 'MOVED_OUT' => $moved, 'UNASSIGNED' => $new] as $status => $tenant) {
            $this->getJson('/api/tenant-lookup?occupancy_status='.$status)->assertOk()->assertJsonCount(1, 'data.tenants')->assertJsonPath('data.tenants.0.id', $tenant->id)->assertJsonPath('data.tenants.0.occupancy_status', $status);
        }
        $this->getJson('/api/tenant-lookup?room_id='.$room->id)->assertOk()->assertJsonPath('data.tenants.0.current_residence.room_id', $room->id);
        $this->getJson('/api/tenant-lookup?room_id='.$other->id)->assertOk()->assertJsonCount(0, 'data.tenants');
        $base = '/api/tenant-lookup?scope=ACTIVE_ROOM&context_room_id='.$room->id;
        $this->getJson($base)->assertOk()->assertJsonCount(1, 'data.tenants')->assertJsonPath('data.tenants.0.id', $active->id);
        $this->getJson($base.'&room_id='.$other->id)->assertOk()->assertJsonCount(0, 'data.tenants');
        $this->getJson($base.'&ids[]='.$new->id)->assertOk()->assertJsonCount(0, 'data.tenants');
        $this->getJson($base.'&exclude_ids[]='.$active->id)->assertOk()->assertJsonCount(0, 'data.tenants');
    }

    public function test_manager_only_sees_assigned_house_members_and_cannot_search_identity(): void
    {
        [, $landlord, $house, $room] = $this->workspace();
        [, $foreign, $foreignHouse, $foreignRoom] = $this->workspace();
        $visible = $this->tenant($landlord, 'Visible');
        $this->tenant($landlord, 'Unassigned');
        $unassignedHouse = BoardingHouse::create(['landlord_id' => $landlord->id, 'name' => 'Nhà chưa phân quyền', 'address' => 'Địa chỉ thử']);
        $unassignedRoom = Room::create(['boarding_house_id' => $unassignedHouse->id, 'room_code' => '201', 'status' => 'AVAILABLE']);
        $sameWorkspaceHidden = $this->tenant($landlord, 'Same workspace hidden');
        $this->stay($sameWorkspaceHidden, $unassignedRoom);
        $hidden = $this->tenant($foreign, 'Hidden');
        $this->stay($visible, $room);
        $this->stay($hidden, $foreignRoom);
        $manager = User::factory()->create();
        $house->managers()->attach($manager->id);
        $this->actingAs($manager, 'sanctum')->getJson('/api/tenant-lookup')->assertOk()->assertJsonCount(1, 'data.tenants')->assertJsonPath('data.tenants.0.id', $visible->id)->assertJsonPath('data.can_search_identity', false);
        $this->getJson('/api/tenant-lookup?search=079123')->assertOk()->assertJsonCount(0, 'data.tenants');
        $this->getJson('/api/tenant-lookup?boarding_house_id='.$foreignHouse->id)->assertNotFound();
        $this->getJson('/api/tenant-lookup?context_room_id='.$foreignRoom->id)->assertNotFound();
        $this->getJson('/api/tenant-lookup?ids[]='.$hidden->id)->assertOk()->assertJsonCount(0, 'data.tenants');
        $this->getJson('/api/tenant-lookup?ids[]='.$sameWorkspaceHidden->id)->assertOk()->assertJsonCount(0, 'data.tenants');
        $this->getJson('/api/tenant-lookup?boarding_house_id='.$unassignedHouse->id)->assertNotFound();
        $this->getJson('/api/tenant-lookup/options')->assertOk()->assertJsonCount(1, 'data.houses')->assertJsonPath('data.can_create', false);
        $this->getJson('/api/tenant-lookup/options?boarding_house_id='.$foreignHouse->id)->assertNotFound();
    }

    public function test_owner_cannot_bypass_scope_and_picker_context_omits_the_legacy_full_list(): void
    {
        [$user, $landlord, , $room] = $this->workspace();
        [, $foreign, $foreignHouse, $foreignRoom] = $this->workspace();
        $owned = $this->tenant($landlord);
        $other = $this->tenant($foreign, 'Foreign');
        // A broken legacy link must not grant access across workspaces.
        $this->stay($other, $room);
        $this->actingAs($user, 'sanctum')->getJson('/api/tenant-lookup?ids[]='.$other->id)->assertOk()->assertJsonCount(0, 'data.tenants');
        $this->getJson('/api/tenant-lookup?boarding_house_id='.$foreignHouse->id)->assertNotFound();
        $this->getJson('/api/tenant-lookup?room_id='.$foreignRoom->id)->assertNotFound();
        $this->getJson('/api/tenant-lookup?context_room_id='.$foreignRoom->id)->assertNotFound();
        $this->getJson('/api/rooms/'.$room->id.'/contract-context')->assertOk()->assertJsonCount(0, 'data.tenants');
        $this->getJson('/api/tenant-lookup?ids[]='.$owned->id)->assertOk()->assertJsonPath('data.tenants.0.id', $owned->id);
        $this->getJson('/api/tenant-lookup?scope=ACTIVE_ROOM')->assertUnprocessable();
    }

    public function test_large_fixtures_return_bounded_payload_without_n_plus_one(): void
    {
        [$user, $landlord, , $room] = $this->workspace();
        $this->actingAs($user, 'sanctum');
        $previous = 0;
        $baselineQueries = null;
        foreach ([100, 1000, 10000] as $total) {
            $rows = [];
            for ($i = $previous; $i < $total; $i++) {
                $rows[] = ['landlord_id' => $landlord->id, 'full_name' => sprintf('Fixture %05d', $i), 'phone' => '0900000000'];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                Tenant::insert($chunk);
            }
            if ($previous === 0) {
                foreach (Tenant::limit(100)->get() as $tenant) {
                    $this->stay($tenant, $room);
                }
            }
            foreach ([20, 100] as $size) {
                $times = [];
                for ($attempt = 0; $attempt < 3; $attempt++) {
                    DB::enableQueryLog();
                    DB::flushQueryLog();
                    $start = hrtime(true);
                    $response = $this->getJson('/api/tenant-lookup?per_page='.$size);
                    $times[] = (hrtime(true) - $start) / 1e6;
                    $queries = count(DB::getQueryLog());
                    DB::disableQueryLog();
                    $response->assertOk()->assertJsonCount($size, 'data.tenants')->assertJsonPath('data.total', $total);
                    $baselineQueries ??= $queries;
                    $this->assertSame($baselineQueries, $queries);
                    $this->assertLessThanOrEqual(10, $queries);
                }
                sort($times);
                fwrite(STDERR, sprintf("\nLookup SQLite: %d tenants, %d/page, median(3) %.1f ms, %d queries, %d bytes\n", $total, $size, $times[1], $queries, strlen($response->getContent())));
            }
            $previous = $total;
        }
    }
}
