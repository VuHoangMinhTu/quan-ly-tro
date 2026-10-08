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
            $this->auth($l)->postJson("/api/rooms/$r->id/utility-meters", $this->payload(['service_id' => $s->id]))->assertStatus(422);
        } $tier = $this->service($r->boardingHouse, ['billing_method' => 'TIERED', 'name' => 'Water']);
        $this->auth($l)->postJson("/api/rooms/$r->id/utility-meters", $this->payload(['service_id' => $tier->id]))->assertCreated();
    }

    public function test_duplicate_active_meter_is_rejected_but_inactive_old_meter_allows_new_one(): void
    {
        $l = $this->landlord();
        $r = $this->room($l);
        $s = $this->service($r->boardingHouse);
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
