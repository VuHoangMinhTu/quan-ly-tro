<?php

namespace Tests\Feature;

use App\Models\BoardingHouse;
use App\Models\Landlord;
use App\Models\Room;
use App\Models\Service;
use App\Models\User;
use App\Models\UtilityMeter;
use App\Models\UtilityReading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UtilityMeterApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_cannot_access_meters(): void
    {
        $r = $this->room($this->landlord());
        $this->getJson("/api/rooms/$r->id/utility-meters")->assertUnauthorized();
        $this->postJson("/api/rooms/$r->id/utility-meters", [])->assertUnauthorized();
        $this->getJson('/api/utility-meters/1')->assertUnauthorized();
    }

    public function test_landlord_can_create_per_unit_meter_with_null_data_response(): void
    {
        $l = $this->landlord();
        $r = $this->room($l);
        $s = $this->service($r->boardingHouse);
        $r->services()->attach($s->id, ['is_active' => true]);
        $this->auth($l)->postJson("/api/rooms/$r->id/utility-meters", $this->payload(['service_id' => $s->id]))->assertCreated()->assertJsonPath('data', null);
        $this->assertDatabaseHas('utility_meters', ['room_id' => $r->id, 'service_id' => $s->id]);
    }

    public function test_meter_rejects_other_room_or_service_from_other_house(): void
    {
        $l = $this->landlord();
        $r = $this->room($l);
        $other = $this->room($this->landlord());
        $service = $this->service($other->boardingHouse);
        $this->auth($l)->postJson("/api/rooms/$other->id/utility-meters", $this->payload(['service_id' => $service->id]))->assertNotFound();
        $this->auth($l)->postJson("/api/rooms/$r->id/utility-meters", $this->payload(['service_id' => $service->id]))->assertStatus(422)->assertJsonValidationErrors('service_id');
    }

    public function test_fixed_and_per_person_services_are_rejected_but_tiered_is_allowed(): void
    {
        $l = $this->landlord();
        $r = $this->room($l);
        foreach (['FIXED', 'PER_PERSON'] as $method) {
            $s = $this->service($r->boardingHouse, ['billing_method' => $method]);
            $r->services()->attach($s->id, ['is_active' => true]);
            $this->auth($l)->postJson("/api/rooms/$r->id/utility-meters", $this->payload(['service_id' => $s->id]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('service_id')
                ->assertJsonPath('errors.service_id.0', 'Đồng hồ chỉ dùng cho dịch vụ tính theo đơn vị hoặc bậc thang.');
        } $tier = $this->service($r->boardingHouse, ['billing_method' => 'TIERED', 'name' => 'Water']);
        $r->services()->attach($tier->id, ['is_active' => true]);
        $this->auth($l)->postJson("/api/rooms/$r->id/utility-meters", $this->payload(['service_id' => $tier->id]))->assertCreated();
    }

    public function test_duplicate_active_meter_is_rejected_but_inactive_old_meter_allows_new_one(): void
    {
        $l = $this->landlord();
        $r = $this->room($l);
        $s = $this->service($r->boardingHouse);
        $r->services()->attach($s->id, ['is_active' => true]);
        UtilityMeter::create(['room_id' => $r->id, 'service_id' => $s->id, 'initial_reading' => 0, 'is_active' => true]);
        $this->auth($l)->postJson("/api/rooms/$r->id/utility-meters", $this->payload(['service_id' => $s->id]))->assertStatus(422);
        UtilityMeter::query()->first()->update(['is_active' => false]);
        $this->auth($l)->postJson("/api/rooms/$r->id/utility-meters", $this->payload(['service_id' => $s->id]))->assertCreated();
    }

    public function test_list_show_update_and_delete_are_owned(): void
    {
        $l = $this->landlord();
        $r = $this->room($l);
        $s = $this->service($r->boardingHouse);
        $m = UtilityMeter::create(['room_id' => $r->id, 'service_id' => $s->id, 'initial_reading' => 10, 'is_active' => true]);
        UtilityReading::create(['utility_meter_id' => $m->id, 'reading_date' => '2026-10-30', 'reading_value' => 20]);
        $this->auth($l)->getJson("/api/rooms/$r->id/utility-meters")
            ->assertOk()
            ->assertJsonPath('data.0.id', $m->id)
            ->assertJsonPath('data.0.latest_reading.reading_date', '2026-10-30');
        $this->auth($l)->getJson("/api/utility-meters/$m->id")
            ->assertOk()
            ->assertJsonPath('data.service.id', $s->id)
            ->assertJsonPath('data.latest_reading.reading_date', '2026-10-30');
        $this->auth($l)->putJson("/api/utility-meters/$m->id", $this->payload(['service_id' => $s->id, 'meter_code' => 'NEW']))->assertOk()->assertJsonPath('data', null);
        $this->auth($l)->deleteJson("/api/utility-meters/$m->id")->assertOk()->assertJsonPath('data', null);
        $this->assertSoftDeleted('utility_meters', ['id' => $m->id]);
    }

    public function test_meter_rejects_unassigned_service_with_422(): void
    {
        $landlord = $this->landlord();
        $room = $this->room($landlord);
        $service = $this->service($room->boardingHouse);

        $this->auth($landlord)->postJson("/api/rooms/$room->id/utility-meters", $this->payload(['service_id' => $service->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('service_id')
            ->assertJsonPath('errors.service_id.0', 'Dịch vụ phải được gán và đang áp dụng cho phòng này.');

        $this->assertDatabaseCount('utility_meters', 0);
    }

    public function test_meter_rejects_inactive_room_service_with_422(): void
    {
        $landlord = $this->landlord();
        $room = $this->room($landlord);
        $service = $this->service($room->boardingHouse);
        $room->services()->attach($service->id, ['is_active' => false]);

        $this->auth($landlord)->postJson("/api/rooms/$room->id/utility-meters", $this->payload(['service_id' => $service->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('service_id')
            ->assertJsonPath('errors.service_id.0', 'Dịch vụ phải được gán và đang áp dụng cho phòng này.');

        $this->assertDatabaseCount('utility_meters', 0);
    }

    public function test_meter_rejects_globally_inactive_service_even_when_room_assignment_is_active(): void
    {
        $landlord = $this->landlord();
        $room = $this->room($landlord);
        $service = $this->service($room->boardingHouse, ['is_active' => false]);
        $room->services()->attach($service->id, ['is_active' => true]);

        $this->auth($landlord)->postJson("/api/rooms/$room->id/utility-meters", $this->payload(['service_id' => $service->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('service_id')
            ->assertJsonPath('errors.service_id.0', 'Dịch vụ phải đang được áp dụng.');

        $this->assertDatabaseCount('utility_meters', 0);
    }

    public function test_assignment_to_another_room_in_same_house_does_not_allow_meter_creation(): void
    {
        $landlord = $this->landlord();
        $room = $this->room($landlord);
        $otherRoom = Room::create(['boarding_house_id' => $room->boarding_house_id, 'room_code' => 'P-other', 'monthly_rent' => 1, 'status' => 'AVAILABLE']);
        $service = $this->service($room->boardingHouse);
        $otherRoom->services()->attach($service->id, ['is_active' => true]);

        $this->auth($landlord)->postJson("/api/rooms/$room->id/utility-meters", $this->payload(['service_id' => $service->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('service_id');

        $this->assertDatabaseCount('utility_meters', 0);
    }

    public function test_inactive_meter_cannot_be_reactivated_after_service_is_unassigned(): void
    {
        $landlord = $this->landlord();
        $room = $this->room($landlord);
        $service = $this->service($room->boardingHouse);
        $meter = UtilityMeter::create(['room_id' => $room->id, 'service_id' => $service->id, 'initial_reading' => 0, 'is_active' => false]);

        $this->auth($landlord)->putJson("/api/utility-meters/$meter->id", $this->payload(['service_id' => $service->id, 'is_active' => true]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('service_id');

        $this->assertDatabaseHas('utility_meters', ['id' => $meter->id, 'is_active' => false]);
    }

    public function test_inactive_meter_can_be_reactivated_for_active_assigned_service(): void
    {
        $landlord = $this->landlord();
        $room = $this->room($landlord);
        $service = $this->service($room->boardingHouse);
        $room->services()->attach($service->id, ['is_active' => true]);
        $meter = UtilityMeter::create(['room_id' => $room->id, 'service_id' => $service->id, 'initial_reading' => 0, 'is_active' => false]);

        $this->auth($landlord)->putJson("/api/utility-meters/$meter->id", $this->payload(['service_id' => $service->id, 'is_active' => true]))
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertDatabaseHas('utility_meters', ['id' => $meter->id, 'is_active' => true]);
    }

    public function test_historical_meter_can_be_deactivated_after_service_unassignment_and_global_deactivation(): void
    {
        $landlord = $this->landlord();
        $room = $this->room($landlord);
        $service = $this->service($room->boardingHouse, ['is_active' => false]);
        $meter = UtilityMeter::create(['room_id' => $room->id, 'service_id' => $service->id, 'initial_reading' => 0, 'is_active' => true]);
        $reading = UtilityReading::create(['utility_meter_id' => $meter->id, 'reading_date' => '2026-10-30', 'reading_value' => 20]);

        $this->auth($landlord)->putJson("/api/utility-meters/$meter->id", $this->payload(['service_id' => $service->id, 'meter_code' => 'Historical', 'is_active' => false]))
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertDatabaseHas('utility_meters', ['id' => $meter->id, 'is_active' => false, 'meter_code' => 'Historical']);
        $this->assertModelExists($reading);
    }

    public function test_historical_inactive_meter_metadata_update_preserves_inactive_status_when_omitted(): void
    {
        $landlord = $this->landlord();
        $room = $this->room($landlord);
        $service = $this->service($room->boardingHouse, ['is_active' => false]);
        $meter = UtilityMeter::create(['room_id' => $room->id, 'service_id' => $service->id, 'initial_reading' => 0, 'is_active' => false]);
        UtilityMeter::create(['room_id' => $room->id, 'service_id' => $service->id, 'initial_reading' => 10, 'is_active' => true]);

        $this->auth($landlord)->putJson("/api/utility-meters/$meter->id", ['service_id' => $service->id, 'initial_reading' => 0, 'meter_code' => 'Old meter'])
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertDatabaseHas('utility_meters', ['id' => $meter->id, 'is_active' => false, 'meter_code' => 'Old meter']);
    }

    public function test_meter_service_change_requires_active_room_assignment(): void
    {
        $landlord = $this->landlord();
        $room = $this->room($landlord);
        $originalService = $this->service($room->boardingHouse);
        $newService = $this->service($room->boardingHouse, ['type' => 'WATER']);
        $meter = UtilityMeter::create(['room_id' => $room->id, 'service_id' => $originalService->id, 'initial_reading' => 0, 'is_active' => true]);

        $this->auth($landlord)->putJson("/api/utility-meters/$meter->id", $this->payload(['service_id' => $newService->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('service_id');

        $this->assertDatabaseHas('utility_meters', ['id' => $meter->id, 'service_id' => $originalService->id]);
    }

    public function test_other_landlord_cannot_update_meter_even_with_active_assignment(): void
    {
        $landlord = $this->landlord();
        $room = $this->room($this->landlord());
        $service = $this->service($room->boardingHouse);
        $room->services()->attach($service->id, ['is_active' => true]);
        $meter = UtilityMeter::create(['room_id' => $room->id, 'service_id' => $service->id, 'initial_reading' => 0, 'is_active' => true]);

        $this->auth($landlord)->putJson("/api/utility-meters/$meter->id", $this->payload(['service_id' => $service->id, 'meter_code' => 'Unauthorized']))
            ->assertNotFound();

        $this->assertDatabaseMissing('utility_meters', ['id' => $meter->id, 'meter_code' => 'Unauthorized']);
    }

    private function landlord(): Landlord
    {
        $u = User::factory()->create();

        return Landlord::create(['user_id' => $u->id, 'full_name' => $u->name, 'phone' => '0901']);
    }

    private function room(Landlord $l): Room
    {
        $b = BoardingHouse::create(['landlord_id' => $l->id, 'name' => 'H'.Room::count(), 'address' => 'A']);

        return Room::create(['boarding_house_id' => $b->id, 'room_code' => 'P'.Room::count(), 'monthly_rent' => 1, 'status' => 'AVAILABLE']);
    }

    private function service(BoardingHouse $b, array $a = []): Service
    {
        return Service::create(['boarding_house_id' => $b->id, 'name' => 'Electricity'.Service::count(), 'type' => 'ELECTRICITY', 'billing_method' => 'PER_UNIT', 'base_price' => 1, ...$a]);
    }

    private function auth(Landlord $l): static
    {
        return $this->withToken($l->user->createToken('t')->plainTextToken);
    }

    private function payload(array $a = []): array
    {
        return ['service_id' => 1, 'meter_code' => 'M1', 'initial_reading' => 0, 'is_active' => true, ...$a];
    }
}
