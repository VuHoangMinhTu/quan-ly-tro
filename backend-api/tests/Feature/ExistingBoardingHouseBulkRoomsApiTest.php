<?php

namespace Tests\Feature;

use App\Models\Landlord;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class ExistingBoardingHouseBulkRoomsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_add_multiple_rooms_with_optional_fields(): void
    {
        $landlord = $this->owner();
        $house = $landlord->boardingHouses()->create(['name' => 'BeeHouse', 'address' => 'Dĩ An']);
        $house->rooms()->create(['room_code' => '100', 'status' => 'AVAILABLE']);

        $this->actingAsOwner($landlord)->postJson("/api/boarding-houses/{$house->id}/rooms/bulk", [
            'rooms' => [
                ['room_code' => ' 101 ', 'area' => 20.5, 'monthly_rent' => 3800000, 'max_tenants' => 2],
                ['room_code' => '102', 'area' => null, 'monthly_rent' => null, 'max_tenants' => null],
            ],
        ])->assertCreated()->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Đã thêm 2 phòng thành công.')
            ->assertJsonPath('data', null);

        $this->assertDatabaseHas('rooms', ['boarding_house_id' => $house->id, 'room_code' => '101', 'area' => 20.5, 'monthly_rent' => 3800000, 'max_tenants' => 2]);
        $this->assertDatabaseHas('rooms', ['boarding_house_id' => $house->id, 'room_code' => '102', 'area' => null, 'monthly_rent' => null, 'max_tenants' => null]);
        $this->assertDatabaseCount('rooms', 3);
    }

    public function test_existing_code_rejects_entire_batch(): void
    {
        $landlord = $this->owner();
        $house = $landlord->boardingHouses()->create(['name' => 'BeeHouse', 'address' => 'Dĩ An']);
        $house->rooms()->create(['room_code' => 'P101', 'status' => 'AVAILABLE']);

        $this->actingAsOwner($landlord)->postJson("/api/boarding-houses/{$house->id}/rooms/bulk", [
            'rooms' => [['room_code' => 'P102'], ['room_code' => 'p101']],
        ])->assertUnprocessable()->assertJsonValidationErrors('rooms.1.room_code');

        $this->assertDatabaseCount('rooms', 1);
    }

    public function test_duplicate_codes_in_batch_are_rejected(): void
    {
        $landlord = $this->owner();
        $house = $landlord->boardingHouses()->create(['name' => 'BeeHouse', 'address' => 'Dĩ An']);

        $this->actingAsOwner($landlord)->postJson("/api/boarding-houses/{$house->id}/rooms/bulk", [
            'rooms' => [['room_code' => ' P101 '], ['room_code' => 'p101']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['rooms.0.room_code', 'rooms.1.room_code']);
        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_foreign_house_returns_404_and_unauthenticated_returns_401(): void
    {
        $landlord = $this->owner();
        $house = $landlord->boardingHouses()->create(['name' => 'BeeHouse', 'address' => 'Dĩ An']);
        $url = "/api/boarding-houses/{$house->id}/rooms/bulk";
        $payload = ['rooms' => [['room_code' => '101']]];

        $this->postJson($url, $payload)->assertUnauthorized();
        $this->actingAsOwner($this->owner())->postJson($url, $payload)->assertNotFound();
        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_failed_room_creation_rolls_back_all_rooms(): void
    {
        $landlord = $this->owner();
        $house = $landlord->boardingHouses()->create(['name' => 'BeeHouse', 'address' => 'Dĩ An']);
        Event::listen('eloquent.creating: '.Room::class, function (Room $room): void {
            if ($room->room_code === '102') {
                throw new RuntimeException('Simulated failure.');
            }
        });

        try {
            $this->actingAsOwner($landlord)->postJson("/api/boarding-houses/{$house->id}/rooms/bulk", [
                'rooms' => [['room_code' => '101'], ['room_code' => '102']],
            ])->assertStatus(500);
            $this->assertDatabaseCount('rooms', 0);
        } finally {
            Event::forget('eloquent.creating: '.Room::class);
        }
    }

    private function owner(): Landlord
    {
        $user = User::factory()->create();

        return Landlord::create(['user_id' => $user->id, 'full_name' => $user->name, 'phone' => '0900000000']);
    }

    private function actingAsOwner(Landlord $landlord): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($landlord->user->createToken('test')->plainTextToken);
    }
}
