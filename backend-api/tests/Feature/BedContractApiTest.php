<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BedContractApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_bed_list_and_creation_are_scoped_to_an_accessible_room(): void
    {
        $contract = Contract::factory()->create();
        $room = $contract->room;
        $room->update(['room_type' => 'DORMITORY']);
        $owner = $room->boardingHouse->landlord->user;

        $this->getJson("/api/rooms/{$room->id}/beds")->assertUnauthorized();
        $this->postJson("/api/rooms/{$room->id}/beds", ['bed_code' => 'G01'])->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/rooms/{$room->id}/beds")->assertNotFound();
        $this->postJson("/api/rooms/{$room->id}/beds", ['bed_code' => 'G01'])->assertNotFound();
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/rooms/{$room->id}/beds", ['bed_code' => 'G01', 'floor_label' => 'Tầng 1'])
            ->assertCreated()->assertJsonPath('success', true);

        $this->getJson("/api/rooms/{$room->id}/beds")->assertOk()->assertJsonPath('data.0.bed_code', 'G01');
        $this->assertDatabaseHas('beds', ['room_id' => $room->id, 'bed_code' => 'G01', 'floor_label' => 'Tầng 1']);
        $this->postJson("/api/rooms/{$room->id}/beds", ['bed_code' => 'G01'])->assertUnprocessable();
    }

    public function test_dorm_contract_uses_only_a_bed_from_its_room(): void
    {
        $contract = Contract::factory()->create();
        $room = $contract->room;
        $bed = $room->beds()->create(['bed_code' => 'G01']);
        $foreign = Contract::factory()->create()->room->beds()->create(['bed_code' => 'G02']);
        $owner = $room->boardingHouse->landlord->user;
        $this->actingAs($owner, 'sanctum');

        $this->putJson("/api/contracts/{$contract->id}", ['contract_type' => 'DORM_BED', 'bed_id' => $foreign->id])
            ->assertUnprocessable()->assertJsonValidationErrors('bed_id');
        $this->putJson("/api/contracts/{$contract->id}", ['contract_type' => 'DORM_BED', 'bed_id' => $bed->id])
            ->assertOk();

        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'contract_type' => 'DORM_BED', 'bed_id' => $bed->id]);
    }

    public function test_different_beds_can_have_active_contracts_but_the_same_bed_cannot_overlap(): void
    {
        $first = Contract::factory()->create(['contract_code' => 'BED-FIRST']);
        $room = $first->room;
        $owner = $room->boardingHouse->landlord->user;
        $bedOne = $room->beds()->create(['bed_code' => 'G01']);
        $bedTwo = $room->beds()->create(['bed_code' => 'G02']);
        $second = Contract::factory()->create(['room_id' => $room->id, 'tenant_id' => Tenant::create(['landlord_id' => $room->boardingHouse->landlord_id, 'full_name' => 'Người thuê thứ hai'])->id, 'contract_code' => 'BED-SECOND']);
        $third = Contract::factory()->create(['room_id' => $room->id, 'tenant_id' => Tenant::create(['landlord_id' => $room->boardingHouse->landlord_id, 'full_name' => 'Người thuê thứ ba'])->id, 'contract_code' => 'BED-THIRD']);
        $this->actingAs($owner, 'sanctum');

        foreach ([[$first, $bedOne], [$second, $bedTwo], [$third, $bedOne]] as [$contract, $bed]) {
            $this->putJson("/api/contracts/{$contract->id}", [
                'contract_type' => 'DORM_BED', 'bed_id' => $bed->id, 'lessor_representative_user_id' => $owner->id,
            ])->assertOk();
            $contract->handoverReports()->create([
                'type' => 'CHECK_IN', 'status' => 'CONFIRMED', 'items' => [['name' => 'Giường', 'quantity' => 1, 'condition' => 'GOOD']], 'confirmed_at' => now(),
            ]);
        }

        $this->putJson("/api/contracts/{$first->id}/status", ['status' => 'ACTIVE'])->assertOk();
        $this->putJson("/api/contracts/{$second->id}/status", ['status' => 'ACTIVE'])->assertOk();
        $this->putJson("/api/contracts/{$third->id}/status", ['status' => 'ACTIVE'])->assertUnprocessable()->assertJsonValidationErrors('start_date');

        $this->assertDatabaseHas('contracts', ['id' => $first->id, 'status' => 'ACTIVE', 'bed_id' => $bedOne->id]);
        $this->assertDatabaseHas('contracts', ['id' => $second->id, 'status' => 'ACTIVE', 'bed_id' => $bedTwo->id]);
        $this->assertDatabaseHas('contracts', ['id' => $third->id, 'status' => 'DRAFT']);

        $wholeRoom = Contract::factory()->create(['room_id' => $room->id, 'tenant_id' => Tenant::create(['landlord_id' => $room->boardingHouse->landlord_id, 'full_name' => 'Người thuê cả phòng'])->id, 'contract_code' => 'WHOLE-ROOM']);
        $this->putJson("/api/contracts/{$wholeRoom->id}", ['lessor_representative_user_id' => $owner->id])->assertOk();
        $wholeRoom->handoverReports()->create([
            'type' => 'CHECK_IN', 'status' => 'CONFIRMED', 'items' => [['name' => 'Phòng', 'quantity' => 1, 'condition' => 'GOOD']], 'confirmed_at' => now(),
        ]);
        $this->putJson("/api/contracts/{$wholeRoom->id}/status", ['status' => 'ACTIVE'])->assertUnprocessable()->assertJsonValidationErrors('start_date');
    }
}
