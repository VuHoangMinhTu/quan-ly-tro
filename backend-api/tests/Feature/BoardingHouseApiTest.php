<?php

namespace Tests\Feature;

use App\Models\BoardingHouse;
use App\Models\Landlord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BoardingHouseApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_boarding_houses(): void
    {
        $boardingHouse = $this->createBoardingHouse($this->createLandlord());
        $payload = $this->validPayload();

        $this->getJson('/api/boarding-houses')->assertUnauthorized();
        $this->postJson('/api/boarding-houses', $payload)->assertUnauthorized();
        $this->getJson("/api/boarding-houses/{$boardingHouse->id}")->assertUnauthorized();
        $this->putJson("/api/boarding-houses/{$boardingHouse->id}", $payload)->assertUnauthorized();
        $this->deleteJson("/api/boarding-houses/{$boardingHouse->id}")->assertUnauthorized();
    }

    public function test_landlord_can_create_a_boarding_house(): void
    {
        $landlord = $this->createLandlord();
        $payload = $this->validPayload();

        $this->withBearerToken($landlord)->postJson('/api/boarding-houses', [
            ...$payload,
            'landlord_id' => 99999,
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Boarding house created successfully.')
            ->assertJsonPath('data', null);

        $this->assertDatabaseHas('boarding_houses', [
            'landlord_id' => $landlord->id,
            'name' => $payload['name'],
        ]);
        $this->assertDatabaseMissing('boarding_houses', ['landlord_id' => 99999]);
    }

    public function test_landlord_can_list_only_their_own_boarding_houses(): void
    {
        $landlord = $this->createLandlord();
        $otherLandlord = $this->createLandlord();
        $first = $this->createBoardingHouse($landlord, ['name' => 'First House']);
        $second = $this->createBoardingHouse($landlord, ['name' => 'Second House']);
        $other = $this->createBoardingHouse($otherLandlord, ['name' => 'Other House']);

        $response = $this->withBearerToken($landlord)->getJson('/api/boarding-houses')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($first->id, $ids);
        $this->assertContains($second->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_landlord_can_view_their_own_boarding_house(): void
    {
        $landlord = $this->createLandlord();
        $boardingHouse = $this->createBoardingHouse($landlord);

        $this->withBearerToken($landlord)->getJson("/api/boarding-houses/{$boardingHouse->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $boardingHouse->id)
            ->assertJsonPath('data.landlord_id', $landlord->id);
    }

    public function test_landlord_cannot_view_another_landlords_boarding_house(): void
    {
        $boardingHouse = $this->createBoardingHouse($this->createLandlord());
        $otherLandlord = $this->createLandlord();

        $this->withBearerToken($otherLandlord)->getJson("/api/boarding-houses/{$boardingHouse->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Not Found.');
    }

    public function test_landlord_can_update_their_own_boarding_house(): void
    {
        $landlord = $this->createLandlord();
        $boardingHouse = $this->createBoardingHouse($landlord);
        $payload = $this->validPayload(['name' => 'Updated House']);

        $this->withBearerToken($landlord)->putJson("/api/boarding-houses/{$boardingHouse->id}", $payload)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);

        $this->assertDatabaseHas('boarding_houses', [
            'id' => $boardingHouse->id,
            'landlord_id' => $landlord->id,
            'name' => 'Updated House',
        ]);
    }

    public function test_landlord_cannot_update_another_landlords_boarding_house(): void
    {
        $boardingHouse = $this->createBoardingHouse($this->createLandlord(), ['name' => 'Protected House']);
        $otherLandlord = $this->createLandlord();

        $this->withBearerToken($otherLandlord)->putJson("/api/boarding-houses/{$boardingHouse->id}", $this->validPayload([
            'name' => 'Changed House',
        ]))->assertNotFound();

        $this->assertDatabaseHas('boarding_houses', [
            'id' => $boardingHouse->id,
            'name' => 'Protected House',
        ]);
    }

    public function test_landlord_can_delete_their_own_boarding_house(): void
    {
        $landlord = $this->createLandlord();
        $boardingHouse = $this->createBoardingHouse($landlord);

        $this->withBearerToken($landlord)->deleteJson("/api/boarding-houses/{$boardingHouse->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Boarding house deleted successfully.')
            ->assertJsonPath('data', null);

        $this->assertSoftDeleted('boarding_houses', ['id' => $boardingHouse->id]);
    }

    public function test_landlord_cannot_delete_another_landlords_boarding_house(): void
    {
        $boardingHouse = $this->createBoardingHouse($this->createLandlord());
        $otherLandlord = $this->createLandlord();

        $this->withBearerToken($otherLandlord)->deleteJson("/api/boarding-houses/{$boardingHouse->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('boarding_houses', ['id' => $boardingHouse->id]);
    }

    public function test_validation_errors_return_422(): void
    {
        $landlord = $this->createLandlord();

        $this->withBearerToken($landlord)->postJson('/api/boarding-houses', [
            'name' => '',
            'address' => '',
            'description' => ['not a string'],
        ])->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['name', 'address', 'description']);
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
            'description' => 'Sample description',
            ...$attributes,
        ]);
    }

    private function withBearerToken(Landlord $landlord): static
    {
        $token = $landlord->user->createToken('test-token')->plainTextToken;

        return $this->withToken($token);
    }

    private function validPayload(array $attributes = []): array
    {
        return [
            'name' => 'Sunrise Boarding House',
            'address' => '123 Nguyen Trai, District 1',
            'description' => 'Clean and convenient rooms.',
            ...$attributes,
        ];
    }
}
