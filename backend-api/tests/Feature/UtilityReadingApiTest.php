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

class UtilityReadingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_cannot_access_readings(): void
    {
        $m = $this->meter($this->landlord());
        $this->getJson("/api/utility-meters/$m->id/readings")->assertUnauthorized();
        $this->postJson("/api/utility-meters/$m->id/readings", [])->assertUnauthorized();
        $this->getJson('/api/utility-readings/1')->assertUnauthorized();
    }

    public function test_landlord_can_add_list_show_update_and_delete_reading(): void
    {
        $l = $this->landlord();
        $m = $this->meter($l, 100);
        $this->auth($l)->postJson("/api/utility-meters/$m->id/readings", $this->payload(['reading_value' => 120]))->assertCreated()->assertJsonPath('data', null);
        $r = UtilityReading::first();
        $this->auth($l)->getJson("/api/utility-meters/$m->id/readings")
            ->assertOk()
            ->assertJsonPath('data.0.id', $r->id)
            ->assertJsonPath('data.0.reading_date', '2026-10-01');
        $this->auth($l)->getJson("/api/utility-readings/$r->id")
            ->assertOk()
            ->assertJsonPath('data.meter.id', $m->id)
            ->assertJsonPath('data.reading_date', '2026-10-01');
        $this->auth($l)->putJson("/api/utility-readings/$r->id", $this->payload(['reading_value' => 130]))->assertOk()->assertJsonPath('data', null);
        $this->auth($l)->deleteJson("/api/utility-readings/$r->id")->assertOk()->assertJsonPath('data', null);
        $this->assertDatabaseMissing('utility_readings', ['id' => $r->id]);
    }

    public function test_reading_date_must_be_unique_and_first_reading_must_not_be_below_initial(): void
    {
        $l = $this->landlord();
        $m = $this->meter($l, 100);
        $this->auth($l)->postJson("/api/utility-meters/$m->id/readings", $this->payload(['reading_value' => 90]))->assertStatus(422)->assertJsonValidationErrors('reading_value');
        UtilityReading::create(['utility_meter_id' => $m->id, 'reading_date' => '2026-09-01', 'reading_value' => 100]);
        $this->auth($l)->postJson("/api/utility-meters/$m->id/readings", $this->payload(['reading_date' => '2026-09-01', 'reading_value' => 110]))->assertStatus(422)->assertJsonValidationErrors('reading_date');
    }

    public function test_readings_cannot_break_previous_or_next_ordering_and_update_ignores_itself(): void
    {
        $l = $this->landlord();
        $m = $this->meter($l);
        $a = UtilityReading::create(['utility_meter_id' => $m->id, 'reading_date' => '2026-09-01', 'reading_value' => 100]);
        $b = UtilityReading::create(['utility_meter_id' => $m->id, 'reading_date' => '2026-11-01', 'reading_value' => 200]);
        $this->auth($l)->postJson("/api/utility-meters/$m->id/readings", $this->payload(['reading_date' => '2026-10-01', 'reading_value' => 90]))->assertStatus(422);
        $this->auth($l)->postJson("/api/utility-meters/$m->id/readings", $this->payload(['reading_date' => '2026-10-01', 'reading_value' => 250]))->assertStatus(422);
        $this->auth($l)->putJson("/api/utility-readings/$b->id", $this->payload(['reading_date' => '2026-11-01', 'reading_value' => 200]))->assertOk()->assertJsonPath('data', null);
    }

    public function test_other_landlord_meter_and_reading_return_404(): void
    {
        $other = $this->meter($this->landlord());
        $r = UtilityReading::create(['utility_meter_id' => $other->id, 'reading_date' => '2026-09-01', 'reading_value' => 1]);
        $l = $this->landlord();
        $this->auth($l)->postJson("/api/utility-meters/$other->id/readings", $this->payload())->assertNotFound();
        $this->auth($l)->getJson("/api/utility-meters/$other->id/readings")->assertNotFound();
        $this->auth($l)->getJson("/api/utility-readings/$r->id")->assertNotFound();
        $this->auth($l)->deleteJson("/api/utility-readings/$r->id")->assertNotFound();
    }

    private function landlord(): Landlord
    {
        $u = User::factory()->create();

        return Landlord::create(['user_id' => $u->id, 'full_name' => $u->name, 'phone' => '0901']);
    }

    private function meter(Landlord $l, float $initial = 0): UtilityMeter
    {
        $b = BoardingHouse::create(['landlord_id' => $l->id, 'name' => 'H'.BoardingHouse::count(), 'address' => 'A']);
        $r = Room::create(['boarding_house_id' => $b->id, 'room_code' => 'P'.Room::count(), 'monthly_rent' => 1, 'status' => 'AVAILABLE']);
        $s = Service::create(['boarding_house_id' => $b->id, 'name' => 'E'.Service::count(), 'type' => 'ELECTRICITY', 'billing_method' => 'PER_UNIT', 'base_price' => 1]);

        return UtilityMeter::create(['room_id' => $r->id, 'service_id' => $s->id, 'initial_reading' => $initial]);
    }

    private function auth(Landlord $l): static
    {
        return $this->withToken($l->user->createToken('t')->plainTextToken);
    }

    private function payload(array $a = []): array
    {
        return ['reading_date' => '2026-10-01', 'reading_value' => 150, 'note' => 'n', ...$a];
    }
}
