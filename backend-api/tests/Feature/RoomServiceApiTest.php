<?php

namespace Tests\Feature;

use App\Models\BoardingHouse;
use App\Models\Invoice;
use App\Models\Landlord;
use App\Models\Room;
use App\Models\Service;
use App\Models\User;
use App\Models\UtilityMeter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RoomServiceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_requests_return_401(): void
    {
        $room = $this->createRoom($this->createBoardingHouse($this->createLandlord()));

        $this->getJson("/api/rooms/{$room->id}/services")->assertUnauthorized();
        $this->putJson("/api/rooms/{$room->id}/services", ['service_ids' => []])->assertUnauthorized();

        $this->assertDatabaseCount('room_services', 0);
    }

    public function test_catalog_services_are_not_automatically_assigned_to_a_room(): void
    {
        $landlord = $this->createLandlord();
        $house = $this->createBoardingHouse($landlord);
        $room = $this->createRoom($house);
        $this->createService($house);

        $this->withBearerToken($landlord)->getJson("/api/rooms/{$room->id}/services")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Lấy danh sách dịch vụ của phòng thành công.')
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseCount('room_services', 0);
    }

    public function test_lists_only_active_room_assignments_with_prices_and_global_status(): void
    {
        $landlord = $this->createLandlord();
        $house = $this->createBoardingHouse($landlord);
        $room = $this->createRoom($house);
        $active = $this->createService($house, ['billing_method' => 'TIERED', 'base_price' => null]);
        $active->priceTiers()->create(['from_quantity' => 0, 'to_quantity' => 50, 'unit_price' => 1900, 'tier_order' => 1]);
        $globallyInactive = $this->createService($house, ['type' => 'WATER', 'is_active' => false]);
        $unassigned = $this->createService($house, ['type' => 'INTERNET']);
        $this->createService($house, ['type' => 'OTHER']);
        $room->services()->attach($active, ['is_active' => true]);
        $room->services()->attach($globallyInactive, ['is_active' => true]);
        $room->services()->attach($unassigned, ['is_active' => false]);

        $this->withBearerToken($landlord)->getJson("/api/rooms/{$room->id}/services")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $active->id)
            ->assertJsonPath('data.0.pivot.is_active', 1)
            ->assertJsonPath('data.0.price_tiers.0.unit_price', '1900.00')
            ->assertJsonPath('data.1.id', $globallyInactive->id)
            ->assertJsonPath('data.1.is_active', false);
    }

    public function test_other_landlords_room_returns_404_before_payload_validation(): void
    {
        $owner = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($owner));
        $other = $this->createLandlord();

        $this->withBearerToken($other)->getJson("/api/rooms/{$room->id}/services")->assertNotFound();
        $this->withBearerToken($other)->putJson("/api/rooms/{$room->id}/services", [
            'service_ids' => 'invalid',
            'landlord_id' => $owner->id,
        ])->assertNotFound();

        $this->assertDatabaseCount('room_services', 0);
    }

    public function test_assigns_selected_services_without_changing_room_prices(): void
    {
        $landlord = $this->createLandlord();
        $house = $this->createBoardingHouse($landlord);
        $room = $this->createRoom($house);
        $electricity = $this->createService($house);
        $water = $this->createService($house, ['type' => 'WATER', 'billing_method' => 'FIXED']);

        $this->withBearerToken($landlord)->putJson("/api/rooms/{$room->id}/services", [
            'service_ids' => [$electricity->id, $water->id],
            'monthly_rent' => 999,
            'boarding_house_id' => 999,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Cập nhật dịch vụ của phòng thành công.')
            ->assertJsonPath('data', null);

        $this->assertDatabaseCount('room_services', 2);
        $this->assertDatabaseHas('room_services', ['room_id' => $room->id, 'service_id' => $electricity->id, 'is_active' => true]);
        $this->assertDatabaseHas('room_services', ['room_id' => $room->id, 'service_id' => $water->id, 'is_active' => true]);
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'monthly_rent' => 2000000, 'boarding_house_id' => $house->id]);
        $this->assertSame([$room->id], $electricity->rooms()->pluck('rooms.id')->all());
    }

    public function test_empty_service_ids_deactivates_all_assignments_without_deleting_history(): void
    {
        $landlord = $this->createLandlord();
        $house = $this->createBoardingHouse($landlord);
        $room = $this->createRoom($house);
        $service = $this->createService($house);
        $room->services()->attach($service, ['is_active' => true]);

        $this->withBearerToken($landlord)->putJson("/api/rooms/{$room->id}/services", ['service_ids' => []])
            ->assertOk()->assertJsonPath('data', null);

        $this->assertDatabaseCount('room_services', 1);
        $this->assertDatabaseHas('room_services', ['room_id' => $room->id, 'service_id' => $service->id, 'is_active' => false]);
        $this->assertModelExists($room);
    }

    public function test_switching_water_service_preserves_meters_and_invoice_snapshots(): void
    {
        $landlord = $this->createLandlord();
        $house = $this->createBoardingHouse($landlord);
        $room = $this->createRoom($house);
        $old = $this->createService($house, ['type' => 'WATER', 'base_price' => 20000]);
        $new = $this->createService($house, ['type' => 'WATER', 'billing_method' => 'FIXED', 'base_price' => 100000]);
        $room->services()->attach($old, ['is_active' => true]);
        $meter = UtilityMeter::create(['room_id' => $room->id, 'service_id' => $old->id, 'initial_reading' => 100, 'is_active' => true]);
        $reading = $meter->readings()->create(['reading_date' => '2026-09-30', 'reading_value' => 120]);
        $invoice = Invoice::create([
            'room_id' => $room->id, 'invoice_code' => 'SNAPSHOT-001', 'billing_period' => '2026-09-01',
            'subtotal' => 400000, 'total_amount' => 400000, 'status' => 'UNPAID',
        ]);
        $item = $invoice->items()->create([
            'type' => 'WATER', 'description' => 'Water snapshot', 'quantity' => 20,
            'unit_price' => 20000, 'amount' => 400000, 'source' => 'AUTO',
        ]);

        $this->withBearerToken($landlord)->putJson("/api/rooms/{$room->id}/services", ['service_ids' => [$new->id]])
            ->assertOk();

        $this->assertDatabaseHas('room_services', ['room_id' => $room->id, 'service_id' => $old->id, 'is_active' => false]);
        $this->assertDatabaseHas('room_services', ['room_id' => $room->id, 'service_id' => $new->id, 'is_active' => true]);
        $this->assertDatabaseHas('utility_meters', ['id' => $meter->id, 'service_id' => $old->id, 'deleted_at' => null]);
        $this->assertModelExists($reading);
        $this->assertDatabaseHas('invoice_items', ['id' => $item->id, 'quantity' => 20, 'unit_price' => 20000, 'amount' => 400000]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'subtotal' => 400000, 'total_amount' => 400000, 'status' => 'UNPAID']);
        $this->assertDatabaseCount('utility_meters', 1);
    }

    public function test_duplicate_ids_and_repeated_puts_do_not_duplicate_assignments(): void
    {
        $landlord = $this->createLandlord();
        $house = $this->createBoardingHouse($landlord);
        $room = $this->createRoom($house);
        $service = $this->createService($house);

        $this->withBearerToken($landlord)->putJson("/api/rooms/{$room->id}/services", ['service_ids' => [$service->id, $service->id]])
            ->assertOk();
        $this->withBearerToken($landlord)->putJson("/api/rooms/{$room->id}/services", ['service_ids' => [$service->id]])
            ->assertOk();

        $this->assertDatabaseCount('room_services', 1);
        $this->assertDatabaseHas('room_services', ['room_id' => $room->id, 'service_id' => $service->id, 'is_active' => true]);
    }

    public function test_reactivates_existing_pivot_and_keeps_its_original_created_at(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 1)->startOfDay());
        $landlord = $this->createLandlord();
        $house = $this->createBoardingHouse($landlord);
        $room = $this->createRoom($house);
        $service = $this->createService($house);
        $room->services()->attach($service, ['is_active' => false]);
        $this->travel(2)->days();

        $this->withBearerToken($landlord)->putJson("/api/rooms/{$room->id}/services", ['service_ids' => [$service->id]])
            ->assertOk();

        $this->assertDatabaseCount('room_services', 1);
        $this->assertDatabaseHas('room_services', [
            'room_id' => $room->id, 'service_id' => $service->id, 'is_active' => true,
            'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-03 00:00:00',
        ]);
    }

    #[DataProvider('invalidSelections')]
    public function test_invalid_service_selection_returns_422_without_changing_assignments(string $state): void
    {
        $landlord = $this->createLandlord();
        $house = $this->createBoardingHouse($landlord);
        $room = $this->createRoom($house);
        $existing = $this->createService($house);
        $room->services()->attach($existing, ['is_active' => true]);
        $serviceHouse = $state === 'other-house' ? $this->createBoardingHouse($landlord) : $house;
        $invalid = $this->createService($serviceHouse, ['is_active' => $state !== 'inactive']);
        if ($state === 'deleted') {
            $invalid->delete();
        }
        $invalidId = $state === 'missing' ? 99999 : $invalid->id;

        $response = $this->withBearerToken($landlord)->putJson("/api/rooms/{$room->id}/services", ['service_ids' => [$invalidId]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('service_ids.0');

        $this->assertSame('Dịch vụ phải đang hoạt động và thuộc cùng nhà trọ với phòng.', $response->json('errors')['service_ids.0'][0]);

        $this->assertDatabaseCount('room_services', 1);
        $this->assertDatabaseHas('room_services', ['room_id' => $room->id, 'service_id' => $existing->id, 'is_active' => true]);
    }

    public static function invalidSelections(): array
    {
        return ['other boarding house' => ['other-house'], 'inactive service' => ['inactive'], 'deleted service' => ['deleted'], 'missing service' => ['missing']];
    }

    #[DataProvider('utilityTypes')]
    public function test_two_services_of_same_utility_type_return_422(string $type, string $label): void
    {
        $landlord = $this->createLandlord();
        $house = $this->createBoardingHouse($landlord);
        $room = $this->createRoom($house);
        $first = $this->createService($house, ['type' => $type, 'billing_method' => 'FIXED']);
        $second = $this->createService($house, ['type' => $type]);

        $this->withBearerToken($landlord)->putJson("/api/rooms/{$room->id}/services", ['service_ids' => [$first->id, $second->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('service_ids')
            ->assertJsonPath('errors.service_ids.0', "Mỗi phòng chỉ được áp dụng một dịch vụ {$label} đang hoạt động.");

        $this->assertDatabaseCount('room_services', 0);
    }

    public static function utilityTypes(): array
    {
        return ['electricity' => ['ELECTRICITY', 'điện'], 'water' => ['WATER', 'nước']];
    }

    public function test_multiple_non_utility_services_of_same_type_are_allowed(): void
    {
        $landlord = $this->createLandlord();
        $house = $this->createBoardingHouse($landlord);
        $room = $this->createRoom($house);
        $first = $this->createService($house, ['type' => 'OTHER', 'billing_method' => 'FIXED']);
        $second = $this->createService($house, ['type' => 'OTHER', 'billing_method' => 'PER_PERSON']);

        $this->withBearerToken($landlord)->putJson("/api/rooms/{$room->id}/services", ['service_ids' => [$first->id, $second->id]])
            ->assertOk();

        $this->assertDatabaseCount('room_services', 2);
        $this->assertDatabaseHas('room_services', ['room_id' => $room->id, 'service_id' => $first->id, 'is_active' => true]);
        $this->assertDatabaseHas('room_services', ['room_id' => $room->id, 'service_id' => $second->id, 'is_active' => true]);
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_assignment_payload_returns_422(array $payload, string $field): void
    {
        $landlord = $this->createLandlord();
        $room = $this->createRoom($this->createBoardingHouse($landlord));

        $this->withBearerToken($landlord)->putJson("/api/rooms/{$room->id}/services", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('room_services', 0);
    }

    public static function invalidPayloads(): array
    {
        return [
            'missing list' => [[], 'service_ids'],
            'non array' => [['service_ids' => '1,2'], 'service_ids'],
            'non integer id' => [['service_ids' => ['invalid']], 'service_ids.0'],
            'non positive id' => [['service_ids' => [0]], 'service_ids.0'],
            'associative array' => [['service_ids' => ['first' => 1]], 'service_ids'],
        ];
    }

    public function test_pivot_failure_rolls_back_the_whole_assignment_change(): void
    {
        $landlord = $this->createLandlord();
        $house = $this->createBoardingHouse($landlord);
        $room = $this->createRoom($house);
        $old = $this->createService($house);
        $new = $this->createService($house, ['type' => 'WATER']);
        $room->services()->attach($old, ['is_active' => true]);
        DB::listen(static function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'insert') && str_contains($query->sql, 'room_services')) {
                throw new RuntimeException('Simulated assignment failure.');
            }
        });

        $this->withBearerToken($landlord)->putJson("/api/rooms/{$room->id}/services", ['service_ids' => [$new->id]])
            ->assertServerError();

        $this->assertDatabaseCount('room_services', 1);
        $this->assertDatabaseHas('room_services', ['room_id' => $room->id, 'service_id' => $old->id, 'is_active' => true]);
        $this->assertDatabaseMissing('room_services', ['room_id' => $room->id, 'service_id' => $new->id]);
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

    private function createRoom(BoardingHouse $house): Room
    {
        return Room::create(['boarding_house_id' => $house->id, 'room_code' => 'P001', 'monthly_rent' => 2000000, 'status' => 'AVAILABLE']);
    }

    private function createService(BoardingHouse $house, array $attributes = []): Service
    {
        return Service::create([
            'boarding_house_id' => $house->id, 'name' => 'Service', 'type' => 'ELECTRICITY',
            'billing_method' => 'PER_UNIT', 'unit' => 'kWh', 'base_price' => 3500, 'is_active' => true,
            ...$attributes,
        ]);
    }

    private function withBearerToken(Landlord $landlord): static
    {
        return $this->withToken($landlord->user->createToken('test-token')->plainTextToken);
    }
}
