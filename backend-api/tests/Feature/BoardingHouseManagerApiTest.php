<?php

namespace Tests\Feature;

use App\Models\BoardingHouse;
use App\Models\Landlord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BoardingHouseManagerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_assigns_existing_account_and_revokes_without_affecting_ownership(): void
    {
        [$user, $house] = $this->owner();
        $manager = User::factory()->create();
        $this->actingAs($user, 'sanctum')->putJson("/api/boarding-houses/{$house->id}/managers", ['manager_emails' => [$manager->email]])->assertOk()->assertJsonPath('data', null);
        $this->getJson("/api/boarding-houses/{$house->id}/managers")->assertOk()->assertJsonPath('data.0.id', $manager->id);
        $this->assertDatabaseHas('boarding_house_managers', ['boarding_house_id' => $house->id, 'user_id' => $manager->id]);
        $this->putJson("/api/boarding-houses/{$house->id}/managers", ['manager_emails' => []])->assertOk();
        $this->assertDatabaseCount('boarding_house_managers', 0);
        $this->assertSame($user->landlord->id, $house->fresh()->landlord_id);
    }

    public function test_manager_cannot_assign_roles_or_view_another_house_profile(): void
    {
        [$owner, $house] = $this->owner();
        [, $foreignHouse] = $this->owner();
        $manager = User::factory()->create();
        $house->managers()->attach($manager->id);
        $this->actingAs($manager, 'sanctum')->putJson("/api/boarding-houses/{$house->id}/managers", ['manager_emails' => []])->assertForbidden();
        $this->getJson("/api/boarding-houses/{$foreignHouse->id}/residence-profile")->assertNotFound();
        $this->putJson("/api/boarding-houses/{$house->id}/residence-profile", ['household_head_name' => 'Chủ hộ pháp lý', 'household_head_identity_number' => '079000000123'])->assertOk();
        $this->getJson("/api/boarding-houses/{$house->id}/residence-profile")->assertOk()->assertJsonPath('data.household_head_name', 'Chủ hộ pháp lý');
        $this->assertDatabaseCount('boarding_house_managers', 1);
    }

    public function test_invalid_assignments_do_not_remove_existing_managers(): void
    {
        [$owner, $house] = $this->owner();
        $manager = User::factory()->create();
        $house->managers()->attach($manager->id);
        $this->actingAs($owner, 'sanctum')->putJson("/api/boarding-houses/{$house->id}/managers", ['manager_emails' => ['missing@example.com']])->assertUnprocessable();
        $this->putJson("/api/boarding-houses/{$house->id}/managers", ['manager_emails' => [$owner->email]])->assertUnprocessable();
        $this->assertDatabaseHas('boarding_house_managers', ['boarding_house_id' => $house->id, 'user_id' => $manager->id]);
    }

    private function owner(): array
    {
        $user = User::factory()->create();
        $landlord = Landlord::create(['user_id' => $user->id, 'full_name' => $user->name, 'phone' => '0900000000']);

        return [$user, BoardingHouse::create(['landlord_id' => $landlord->id, 'name' => 'BeeHouse', 'address' => 'Địa chỉ'])];
    }
}
