<?php

namespace Tests\Feature;

use App\Models\BoardingHouse;
use App\Models\Landlord;
use App\Models\Room;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServiceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_services(): void
    {
        $service = $this->createService($this->createBoardingHouse($this->createLandlord()));

        $this->getJson("/api/boarding-houses/{$service->boarding_house_id}/services")->assertUnauthorized();
        $this->postJson("/api/boarding-houses/{$service->boarding_house_id}/services", $this->servicePayload())->assertUnauthorized();
        $this->getJson("/api/services/{$service->id}")->assertUnauthorized();
        $this->putJson("/api/services/{$service->id}", $this->servicePayload())->assertUnauthorized();
        $this->deleteJson("/api/services/{$service->id}")->assertUnauthorized();
    }

    public function test_landlord_can_create_service_for_own_boarding_house(): void
    {
        $landlord = $this->createLandlord();
        $boardingHouse = $this->createBoardingHouse($landlord);

        $this->withBearerToken($landlord)->postJson("/api/boarding-houses/{$boardingHouse->id}/services", [
            ...$this->servicePayload(),
            'boarding_house_id' => 99999,
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);

        $this->assertDatabaseHas('services', [
            'boarding_house_id' => $boardingHouse->id,
            'name' => 'Electricity',
        ]);
    }

    public function test_landlord_cannot_create_service_for_another_landlords_boarding_house(): void
    {
        $boardingHouse = $this->createBoardingHouse($this->createLandlord());
        $landlord = $this->createLandlord();

        $this->withBearerToken($landlord)->postJson("/api/boarding-houses/{$boardingHouse->id}/services", $this->servicePayload())
            ->assertNotFound();
    }

    public function test_landlord_lists_services_only_from_own_boarding_house(): void
    {
        $landlord = $this->createLandlord();
        $own = $this->createBoardingHouse($landlord);
        $other = $this->createBoardingHouse($this->createLandlord());
        $ownService = $this->createService($own);
        $otherService = $this->createService($other, ['name' => 'Other']);

        $response = $this->withBearerToken($landlord)->getJson("/api/boarding-houses/{$own->id}/services")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame($ownService->id, $response->json('data.0.id'));
        $this->assertNotSame($otherService->id, $response->json('data.0.id'));
    }

    public function test_landlord_can_view_update_and_delete_own_service(): void
    {
        $landlord = $this->createLandlord();
        $service = $this->createService($this->createBoardingHouse($landlord));

        $this->withBearerToken($landlord)->getJson("/api/services/{$service->id}")->assertOk()->assertJsonPath('data.id', $service->id);
        $this->withBearerToken($landlord)->putJson("/api/services/{$service->id}", $this->servicePayload(['name' => 'Updated Electricity']))
            ->assertOk()->assertJsonPath('data', null);
        $this->withBearerToken($landlord)->deleteJson("/api/services/{$service->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);

        $this->assertDatabaseHas('services', ['id' => $service->id, 'name' => 'Updated Electricity']);
        $this->assertSoftDeleted('services', ['id' => $service->id]);
    }

    public function test_landlord_cannot_manage_another_landlords_service(): void
    {
        $service = $this->createService($this->createBoardingHouse($this->createLandlord()));
        $landlord = $this->createLandlord();

        $this->withBearerToken($landlord)->getJson("/api/services/{$service->id}")->assertNotFound();
        $this->withBearerToken($landlord)->putJson("/api/services/{$service->id}", $this->servicePayload())->assertNotFound();
        $this->withBearerToken($landlord)->deleteJson("/api/services/{$service->id}")->assertNotFound();
    }

    public function test_fixed_per_unit_and_per_person_require_base_price(): void
    {
        $landlord = $this->createLandlord();
        $boardingHouse = $this->createBoardingHouse($landlord);

        foreach (['FIXED', 'PER_UNIT', 'PER_PERSON'] as $method) {
            $this->withBearerToken($landlord)->postJson("/api/boarding-houses/{$boardingHouse->id}/services", $this->servicePayload([
                'billing_method' => $method,
                'base_price' => null,
            ]))->assertStatus(422)->assertJsonValidationErrors('base_price');
        }
    }

    public function test_tiered_allows_null_base_price_and_invalid_enums_return_422(): void
    {
        $landlord = $this->createLandlord();
        $boardingHouse = $this->createBoardingHouse($landlord);

        $this->withBearerToken($landlord)->postJson("/api/boarding-houses/{$boardingHouse->id}/services", $this->servicePayload([
            'billing_method' => 'TIERED',
            'base_price' => null,
        ]))->assertCreated()->assertJsonPath('data', null);

        $this->assertDatabaseHas('services', [
            'boarding_house_id' => $boardingHouse->id,
            'billing_method' => 'TIERED',
            'base_price' => null,
        ]);

        $this->withBearerToken($landlord)->postJson("/api/boarding-houses/{$boardingHouse->id}/services", $this->servicePayload([
            'type' => 'INVALID',
            'billing_method' => 'INVALID',
        ]))->assertStatus(422)->assertJsonValidationErrors(['type', 'billing_method']);
    }

    #[DataProvider('utilityTypes')]
    public function test_type_update_returns_422_if_any_assigned_room_has_that_active_utility_type(string $type, string $label): void
    {
        $landlord = $this->createLandlord();
        $house = $this->createBoardingHouse($landlord);
        $firstRoom = $this->createRoom($house);
        $secondRoom = $this->createRoom($house, 'P002');
        $candidate = $this->createService($house, ['type' => 'OTHER']);
        $existing = $this->createService($house, ['type' => $type]);
        $firstRoom->services()->attach($candidate, ['is_active' => true]);
        $secondRoom->services()->attach($candidate, ['is_active' => true]);
        $secondRoom->services()->attach($existing, ['is_active' => true]);

        $this->withBearerToken($landlord)->putJson("/api/services/{$candidate->id}", $this->servicePayload(['type' => $type]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type')
            ->assertJsonPath('errors.type.0', "Không thể thay đổi dịch vụ: phòng đang áp dụng một dịch vụ {$label} khác.");

        $this->assertDatabaseHas('services', ['id' => $candidate->id, 'type' => 'OTHER', 'is_active' => true]);
        $this->assertDatabaseHas('room_services', ['room_id' => $secondRoom->id, 'service_id' => $candidate->id, 'is_active' => true]);
        $this->assertDatabaseHas('room_services', ['room_id' => $secondRoom->id, 'service_id' => $existing->id, 'is_active' => true]);
    }

    public static function utilityTypes(): array
    {
        return ['electricity' => ['ELECTRICITY', 'điện'], 'water' => ['WATER', 'nước']];
    }

    public function test_reactivating_catalog_service_returns_422_if_assigned_room_has_active_same_type(): void
    {
        $landlord = $this->createLandlord();
        $house = $this->createBoardingHouse($landlord);
        $room = $this->createRoom($house);
        $candidate = $this->createService($house, ['type' => 'WATER', 'is_active' => false]);
        $existing = $this->createService($house, ['type' => 'WATER']);
        $room->services()->attach($candidate, ['is_active' => true]);
        $room->services()->attach($existing, ['is_active' => true]);

        $this->withBearerToken($landlord)->putJson("/api/services/{$candidate->id}", $this->servicePayload(['type' => 'WATER']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active')
            ->assertJsonPath('errors.is_active.0', 'Không thể thay đổi dịch vụ: phòng đang áp dụng một dịch vụ nước khác.');

        $this->assertDatabaseHas('services', ['id' => $candidate->id, 'is_active' => false]);
        $this->assertDatabaseCount('room_services', 2);
    }

    #[DataProvider('nonConflictingStates')]
    public function test_catalog_type_update_succeeds_when_no_active_room_utility_conflict_exists(bool $candidateActive, bool $otherGloballyActive, bool $otherAssigned): void
    {
        $landlord = $this->createLandlord();
        $house = $this->createBoardingHouse($landlord);
        $room = $this->createRoom($house);
        $candidate = $this->createService($house, ['type' => 'OTHER']);
        $existing = $this->createService($house, ['type' => 'WATER', 'is_active' => $otherGloballyActive]);
        $room->services()->attach($candidate, ['is_active' => true]);
        $room->services()->attach($existing, ['is_active' => $otherAssigned]);

        $this->withBearerToken($landlord)->putJson("/api/services/{$candidate->id}", $this->servicePayload([
            'type' => 'WATER', 'is_active' => $candidateActive,
        ]))->assertOk()->assertJsonPath('data', null);

        $this->assertDatabaseHas('services', ['id' => $candidate->id, 'type' => 'WATER', 'is_active' => $candidateActive]);
        $this->assertDatabaseHas('room_services', ['room_id' => $room->id, 'service_id' => $existing->id, 'is_active' => $otherAssigned]);
    }

    public static function nonConflictingStates(): array
    {
        return [
            'other pivot inactive' => [true, true, false],
            'other catalog inactive' => [true, false, true],
            'candidate becoming inactive' => [false, true, true],
        ];
    }

    public function test_unrelated_catalog_price_edit_does_not_change_existing_assignments(): void
    {
        $landlord = $this->createLandlord();
        $house = $this->createBoardingHouse($landlord);
        $room = $this->createRoom($house);
        $service = $this->createService($house);
        $room->services()->attach($service, ['is_active' => true]);

        $this->withBearerToken($landlord)->putJson("/api/services/{$service->id}", $this->servicePayload([
            'name' => 'New name', 'base_price' => 4500,
        ]))->assertOk()->assertJsonPath('data', null);

        $this->assertDatabaseHas('services', ['id' => $service->id, 'name' => 'New name', 'base_price' => 4500]);
        $this->assertDatabaseHas('room_services', ['room_id' => $room->id, 'service_id' => $service->id, 'is_active' => true]);
    }

    private function createLandlord(): Landlord
    {
        $user = User::factory()->create();

        return Landlord::create(['user_id' => $user->id, 'full_name' => $user->name, 'phone' => '+84901234567']);
    }

    private function createBoardingHouse(Landlord $landlord): BoardingHouse
    {
        return BoardingHouse::create(['landlord_id' => $landlord->id, 'name' => 'House', 'address' => 'Address']);
    }

    private function createService(BoardingHouse $boardingHouse, array $attributes = []): Service
    {
        return Service::create(['boarding_house_id' => $boardingHouse->id, ...$this->servicePayload(), ...$attributes]);
    }

    private function createRoom(BoardingHouse $boardingHouse, string $roomCode = 'P001'): Room
    {
        return Room::create(['boarding_house_id' => $boardingHouse->id, 'room_code' => $roomCode, 'monthly_rent' => 2000000, 'status' => 'AVAILABLE']);
    }

    private function withBearerToken(Landlord $landlord): static
    {
        return $this->withToken($landlord->user->createToken('test-token')->plainTextToken);
    }

    private function servicePayload(array $attributes = []): array
    {
        return ['name' => 'Electricity', 'type' => 'ELECTRICITY', 'billing_method' => 'PER_UNIT', 'unit' => 'kWh', 'base_price' => 3800, 'is_active' => true, ...$attributes];
    }
}
