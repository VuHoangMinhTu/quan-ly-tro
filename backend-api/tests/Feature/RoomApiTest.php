<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\BoardingHouse;
use App\Models\Landlord;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_rooms(): void
    {
        $boardingHouse = $this->createBoardingHouse($this->createLandlord());
        $room = $this->createRoom($boardingHouse);

        $this->getJson("/api/boarding-houses/{$boardingHouse->id}/rooms")->assertUnauthorized();
        $this->postJson("/api/boarding-houses/{$boardingHouse->id}/rooms", $this->roomPayload())->assertUnauthorized();
        $this->getJson("/api/rooms/{$room->id}")->assertUnauthorized();
        $this->putJson("/api/rooms/{$room->id}", $this->roomPayload())->assertUnauthorized();
        $this->deleteJson("/api/rooms/{$room->id}")->assertUnauthorized();
        $this->getJson('/api/amenities')->assertUnauthorized();
        $this->postJson('/api/amenities', ['name' => 'Air conditioner'])->assertUnauthorized();
    }

    public function test_landlord_can_create_room_in_their_own_boarding_house(): void
    {
        $landlord = $this->createLandlord();
        $boardingHouse = $this->createBoardingHouse($landlord);

        $this->withBearerToken($landlord)->postJson("/api/boarding-houses/{$boardingHouse->id}/rooms", [
            ...$this->roomPayload(),
            'boarding_house_id' => 99999,
            'landlord_id' => 99999,
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Room created successfully.')
            ->assertJsonPath('data', null);

        $this->assertDatabaseHas('rooms', [
            'boarding_house_id' => $boardingHouse->id,
            'room_code' => 'P001',
        ]);
    }

    public function test_landlord_cannot_create_room_in_another_landlords_boarding_house(): void
    {
        $otherBoardingHouse = $this->createBoardingHouse($this->createLandlord());
        $landlord = $this->createLandlord();

        $this->withBearerToken($landlord)
            ->postJson("/api/boarding-houses/{$otherBoardingHouse->id}/rooms", $this->roomPayload())
            ->assertNotFound();

        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_room_code_may_duplicate_across_different_boarding_houses(): void
    {
        $landlord = $this->createLandlord();
        $firstBoardingHouse = $this->createBoardingHouse($landlord, ['name' => 'First House']);
        $secondBoardingHouse = $this->createBoardingHouse($landlord, ['name' => 'Second House']);

        $this->withBearerToken($landlord)
            ->postJson("/api/boarding-houses/{$firstBoardingHouse->id}/rooms", $this->roomPayload())
            ->assertCreated();
        $this->withBearerToken($landlord)
            ->postJson("/api/boarding-houses/{$secondBoardingHouse->id}/rooms", $this->roomPayload())
            ->assertCreated();

        $this->assertDatabaseCount('rooms', 2);
    }

    public function test_room_code_cannot_duplicate_inside_the_same_boarding_house(): void
    {
        $landlord = $this->createLandlord();
        $boardingHouse = $this->createBoardingHouse($landlord);
        $this->createRoom($boardingHouse, ['room_code' => 'P001']);

        $this->withBearerToken($landlord)
            ->postJson("/api/boarding-houses/{$boardingHouse->id}/rooms", $this->roomPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('room_code');
    }

    public function test_landlord_can_list_rooms_of_their_own_boarding_house(): void
    {
        $landlord = $this->createLandlord();
        $boardingHouse = $this->createBoardingHouse($landlord);
        $amenity = Amenity::create(['name' => 'Wi-Fi']);
        $firstRoom = $this->createRoom($boardingHouse, ['room_code' => 'P001']);
        $firstRoom->amenities()->attach($amenity);
        $secondRoom = $this->createRoom($boardingHouse, ['room_code' => 'P002']);

        $response = $this->withBearerToken($landlord)
            ->getJson("/api/boarding-houses/{$boardingHouse->id}/rooms")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');

        $rooms = collect($response->json('data'))->keyBy('id');
        $this->assertArrayHasKey($firstRoom->id, $rooms->all());
        $this->assertArrayHasKey($secondRoom->id, $rooms->all());
        $this->assertSame('Wi-Fi', $rooms[$firstRoom->id]['amenities'][0]['name']);
    }

    public function test_landlord_cannot_list_rooms_of_another_landlord(): void
    {
        $boardingHouse = $this->createBoardingHouse($this->createLandlord());
        $otherLandlord = $this->createLandlord();

        $this->withBearerToken($otherLandlord)
            ->getJson("/api/boarding-houses/{$boardingHouse->id}/rooms")
            ->assertNotFound();
    }

    public function test_landlord_can_view_their_own_room(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($landlord));

        $this->withBearerToken($landlord)->getJson("/api/rooms/{$room->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $room->id)
            ->assertJsonStructure(['data' => ['amenities']]);
    }

    public function test_landlord_cannot_view_another_landlords_room(): void
    {
        $room = $this->createRoom($this->createBoardingHouse($this->createLandlord()));
        $otherLandlord = $this->createLandlord();

        $this->withBearerToken($otherLandlord)->getJson("/api/rooms/{$room->id}")
            ->assertNotFound();
    }

    public function test_landlord_can_update_their_own_room(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($landlord));

        $this->withBearerToken($landlord)->putJson("/api/rooms/{$room->id}", $this->roomPayload([
            'room_code' => 'P002',
            'monthly_rent' => 2500000,
            'status' => 'RENTED',
        ]))->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);

        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'room_code' => 'P002', 'status' => 'RENTED']);
    }

    public function test_landlord_cannot_update_another_landlords_room(): void
    {
        $room = $this->createRoom($this->createBoardingHouse($this->createLandlord()), ['room_code' => 'P001']);
        $otherLandlord = $this->createLandlord();

        $this->withBearerToken($otherLandlord)
            ->putJson("/api/rooms/{$room->id}", $this->roomPayload(['room_code' => 'P999']))
            ->assertNotFound();

        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'room_code' => 'P001']);
    }

    public function test_landlord_can_delete_their_own_room(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($landlord));

        $this->withBearerToken($landlord)->deleteJson("/api/rooms/{$room->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);

        $this->assertSoftDeleted('rooms', ['id' => $room->id]);
    }

    public function test_landlord_cannot_delete_another_landlords_room(): void
    {
        $room = $this->createRoom($this->createBoardingHouse($this->createLandlord()));
        $otherLandlord = $this->createLandlord();

        $this->withBearerToken($otherLandlord)->deleteJson("/api/rooms/{$room->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
    }

    public function test_room_can_attach_amenities(): void
    {
        $landlord = $this->createLandlord();
        $boardingHouse = $this->createBoardingHouse($landlord);
        $wifi = Amenity::create(['name' => 'Wi-Fi']);
        $airConditioner = Amenity::create(['name' => 'Air conditioner']);

        $this->withBearerToken($landlord)
            ->postJson("/api/boarding-houses/{$boardingHouse->id}/rooms", $this->roomPayload([
                'amenity_ids' => [$wifi->id, $airConditioner->id],
            ]))->assertCreated()
            ->assertJsonPath('data', null);

        $room = Room::query()->firstOrFail();
        $this->assertDatabaseCount('room_amenities', 2);
        $this->assertTrue($room->amenities()->whereKey($wifi->id)->exists());
    }

    public function test_room_can_update_amenities_using_sync(): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($landlord));
        $wifi = Amenity::create(['name' => 'Wi-Fi']);
        $fan = Amenity::create(['name' => 'Fan']);
        $airConditioner = Amenity::create(['name' => 'Air conditioner']);
        $room->amenities()->attach([$wifi->id, $fan->id]);

        $this->withBearerToken($landlord)
            ->putJson("/api/rooms/{$room->id}", $this->roomPayload([
                'amenity_ids' => [$fan->id, $airConditioner->id],
            ]))->assertOk()
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('room_amenities', ['room_id' => $room->id, 'amenity_id' => $wifi->id]);
        $this->assertDatabaseHas('room_amenities', ['room_id' => $room->id, 'amenity_id' => $fan->id]);
        $this->assertDatabaseHas('room_amenities', ['room_id' => $room->id, 'amenity_id' => $airConditioner->id]);
    }

    public function test_amenities_endpoint_returns_available_amenities(): void
    {
        $landlord = $this->createLandlord();
        Amenity::create(['name' => 'Wi-Fi']);
        Amenity::create(['name' => 'Air conditioner']);

        $this->withBearerToken($landlord)->getJson('/api/amenities')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Air conditioner');
    }

    public function test_landlord_can_create_a_global_amenity_and_assign_it_to_multiple_rooms(): void
    {
        $landlord = $this->createLandlord();
        $boardingHouse = $this->createBoardingHouse($landlord);
        $firstRoom = $this->createRoom($boardingHouse, ['room_code' => 'P001']);
        $secondRoom = $this->createRoom($boardingHouse, ['room_code' => 'P002']);
        $token = $landlord->user->createToken('test-token')->plainTextToken;

        $amenityId = $this->withToken($token)
            ->postJson('/api/amenities', ['name' => '  Máy lọc nước  '])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.amenity.name', 'Máy lọc nước')
            ->json('data.amenity.id');

        $this->withToken($token)->putJson("/api/rooms/{$firstRoom->id}", $this->roomPayload([
            'room_code' => 'P001',
            'amenity_ids' => [$amenityId],
        ]))->assertOk();
        $this->withToken($token)->putJson("/api/rooms/{$secondRoom->id}", $this->roomPayload([
            'room_code' => 'P002',
            'amenity_ids' => [$amenityId],
        ]))->assertOk();

        $this->assertDatabaseHas('room_amenities', ['room_id' => $firstRoom->id, 'amenity_id' => $amenityId]);
        $this->assertDatabaseHas('room_amenities', ['room_id' => $secondRoom->id, 'amenity_id' => $amenityId]);
    }

    public function test_duplicate_amenity_name_is_rejected_after_trimming(): void
    {
        $landlord = $this->createLandlord();
        Amenity::create(['name' => 'Máy lạnh']);

        $this->withBearerToken($landlord)
            ->postJson('/api/amenities', ['name' => '  Máy lạnh  '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_room_validation_returns_422(): void
    {
        $landlord = $this->createLandlord();
        $boardingHouse = $this->createBoardingHouse($landlord);

        $this->withBearerToken($landlord)
            ->postJson("/api/boarding-houses/{$boardingHouse->id}/rooms", [
                'room_code' => '',
                'monthly_rent' => -1,
                'max_tenants' => 0,
                'status' => 'INVALID',
                'amenity_ids' => ['invalid'],
            ])->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors([
                'room_code', 'monthly_rent', 'max_tenants', 'status', 'amenity_ids.0',
            ]);
    }

    private function createLandlord(): Landlord
    {
        $user = User::factory()->create();

        return Landlord::create([
            'user_id' => $user->id,
            'full_name' => $user->name,
            'phone' => '+84901234567',
        ]);
    }

    private function createBoardingHouse(Landlord $landlord, array $attributes = []): BoardingHouse
    {
        return BoardingHouse::create([
            'landlord_id' => $landlord->id,
            'name' => 'Sample Boarding House',
            'address' => '123 Sample Street',
            ...$attributes,
        ]);
    }

    private function createRoom(BoardingHouse $boardingHouse, array $attributes = []): Room
    {
        return Room::create([
            ...$this->roomPayload(),
            'boarding_house_id' => $boardingHouse->id,
            ...$attributes,
        ]);
    }

    private function withBearerToken(Landlord $landlord): static
    {
        return $this->withToken($landlord->user->createToken('test-token')->plainTextToken);
    }

    private function roomPayload(array $attributes = []): array
    {
        return [
            'room_code' => 'P001',
            'room_name' => 'Room P001',
            'area' => 25.5,
            'monthly_rent' => 2000000,
            'max_tenants' => 2,
            'status' => 'AVAILABLE',
            'description' => 'Clean room.',
            ...$attributes,
        ];
    }
}
