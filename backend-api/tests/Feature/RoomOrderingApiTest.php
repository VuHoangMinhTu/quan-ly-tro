<?php

namespace Tests\Feature;

use App\Models\BoardingHouse;
use App\Models\Landlord;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class RoomOrderingApiTest extends TestCase
{
    use RefreshDatabase;

    private function house(): BoardingHouse
    {
        $user = User::factory()->create();
        $landlord = Landlord::create(['user_id' => $user->id, 'full_name' => $user->name, 'phone' => '0900000000']);
        $this->actingAs($user, 'sanctum');

        return BoardingHouse::create(['landlord_id' => $landlord->id, 'name' => 'Nhà thử', 'address' => 'Địa chỉ thử']);
    }

    private function room(BoardingHouse $house, string $code, array $extra = []): Room
    {
        return $house->rooms()->create(['room_code' => $code, 'status' => 'AVAILABLE', ...$extra]);
    }

    #[TestWith([['1', '2', '10', '3'], ['1', '2', '3', '10']])]
    #[TestWith([['P1', 'P10', 'P2'], ['P1', 'P2', 'P10']])]
    #[TestWith([['G10', 'G02', 'G01'], ['G01', 'G02', 'G10']])]
    #[TestWith([['110', '102', '101'], ['101', '102', '110']])]
    public function test_legacy_rooms_use_natural_order_consistently_across_lists(array $codes, array $expected): void
    {
        $house = $this->house();
        foreach ($codes as $code) {
            $this->room($house, $code);
        }

        foreach (["/api/boarding-houses/{$house->id}/rooms", "/api/residence/boarding-houses/{$house->id}/rooms"] as $endpoint) {
            $response = $this->getJson($endpoint)->assertOk();
            $this->assertSame($expected, array_column($response->json('data'), 'room_code'));
        }
    }

    public function test_floor_order_and_custom_room_order_take_priority_over_codes(): void
    {
        $house = $this->house();
        $second = $house->floors()->create(['name' => '1', 'sort_order' => 2]);
        $first = $house->floors()->create(['name' => '99', 'sort_order' => 1]);
        $this->room($house, 'P2', ['floor_id' => $first->id, 'sort_order' => 3]);
        $this->room($house, 'P1', ['floor_id' => $first->id, 'sort_order' => 2]);
        $this->room($house, 'P10', ['floor_id' => $first->id, 'sort_order' => 1]);
        $this->room($house, 'A1', ['floor_id' => $second->id]);
        $this->room($house, '0');

        $response = $this->getJson("/api/boarding-houses/{$house->id}/rooms")->assertOk();

        $this->assertSame(['P10', 'P1', 'P2', 'A1', '0'], array_column($response->json('data'), 'room_code'));
    }

    public function test_single_and_bulk_creation_append_in_preview_order_within_each_group(): void
    {
        $house = $this->house();
        $floor = $house->floors()->create(['name' => 'Tầng 1', 'sort_order' => 1]);
        $this->room($house, 'OLD', ['floor_id' => $floor->id, 'sort_order' => 5]);
        $this->postJson("/api/boarding-houses/{$house->id}/rooms/bulk", ['floor_id' => $floor->id, 'rooms' => [['room_code' => 'P10'], ['room_code' => 'P1']]])->assertCreated();
        $this->postJson("/api/boarding-houses/{$house->id}/rooms", ['room_code' => 'P2', 'floor_id' => $floor->id, 'status' => 'AVAILABLE'])->assertCreated();
        $this->postJson("/api/boarding-houses/{$house->id}/rooms", ['room_code' => 'G1', 'status' => 'AVAILABLE'])->assertCreated();

        $this->assertDatabaseHas('rooms', ['room_code' => 'P10', 'sort_order' => 6]);
        $this->assertDatabaseHas('rooms', ['room_code' => 'P1', 'sort_order' => 7]);
        $this->assertDatabaseHas('rooms', ['room_code' => 'P2', 'sort_order' => 8]);
        $this->assertDatabaseHas('rooms', ['room_code' => 'G1', 'sort_order' => 1]);
    }

    public function test_house_onboarding_assigns_sequential_order_without_renaming_codes(): void
    {
        $this->house();
        $response = $this->postJson('/api/boarding-houses/with-rooms', ['idempotency_key' => (string) Str::uuid(), 'boarding_house' => ['name' => 'Nhà mới', 'address' => 'Địa chỉ'],
            'rooms' => [['room_code' => 'Tự chọn'], ['room_code' => '10'], ['room_code' => '1']]])->assertCreated();

        foreach (['Tự chọn' => 1, '10' => 2, '1' => 3] as $code => $order) {
            $this->assertDatabaseHas('rooms', ['boarding_house_id' => $response->json('data.id'), 'room_code' => (string) $code, 'sort_order' => $order]);
        }
    }

    public function test_reorder_persists_and_does_not_modify_another_floor(): void
    {
        $house = $this->house();
        $a = $this->room($house, '1');
        $b = $this->room($house, '2');
        $floor = $house->floors()->create(['name' => 'Tầng', 'sort_order' => 1]);
        $other = $this->room($house, '10', ['floor_id' => $floor->id, 'sort_order' => 9]);

        $this->putJson("/api/boarding-houses/{$house->id}/rooms/order", ['room_ids' => [$b->id, $a->id]])->assertOk();

        $this->assertDatabaseHas('rooms', ['id' => $b->id, 'sort_order' => 1]);
        $this->assertDatabaseHas('rooms', ['id' => $a->id, 'sort_order' => 2]);
        $this->assertDatabaseHas('rooms', ['id' => $other->id, 'sort_order' => 9]);
        foreach (range(1, 2) as $attempt) {
            $this->assertSame(['10', '2', '1'], array_column($this->getJson("/api/boarding-houses/{$house->id}/rooms")->assertOk()->json('data'), 'room_code'));
        }
    }

    public function test_reorder_rejects_foreign_duplicate_and_stale_room_ids_without_partial_updates(): void
    {
        $foreignHouse = $this->house();
        $foreign = $this->room($foreignHouse, 'FOREIGN');
        $house = $this->house();
        $a = $this->room($house, '1', ['sort_order' => 7]);
        $b = $this->room($house, '2', ['sort_order' => 8]);
        foreach ([[$a->id, $foreign->id], [$a->id], [$a->id, $a->id]] as $ids) {
            $this->putJson("/api/boarding-houses/{$house->id}/rooms/order", ['room_ids' => $ids])->assertUnprocessable();
        }
        $this->putJson("/api/boarding-houses/{$foreignHouse->id}/rooms/order", ['room_ids' => [$foreign->id]])->assertNotFound();

        $this->assertDatabaseHas('rooms', ['id' => $a->id, 'sort_order' => 7]);
        $this->assertDatabaseHas('rooms', ['id' => $b->id, 'sort_order' => 8]);
    }

    public function test_legacy_restore_preserves_custom_order_or_places_null_order_at_end(): void
    {
        $house = $this->house();
        $custom = $this->room($house, 'P10', ['sort_order' => 1]);
        $legacy = $this->room($house, 'P1');
        $custom->delete();
        $legacy->delete();
        $this->room($house, 'P2', ['sort_order' => 2]);
        $custom->restore();
        $legacy->restore();

        $this->assertSame(['P10', 'P2', 'P1'], array_column($this->getJson("/api/boarding-houses/{$house->id}/rooms")->assertOk()->json('data'), 'room_code'));
    }

    public function test_reorder_requires_authentication(): void
    {
        $this->putJson('/api/boarding-houses/1/rooms/order', ['room_ids' => [1]])->assertUnauthorized();
    }
}
