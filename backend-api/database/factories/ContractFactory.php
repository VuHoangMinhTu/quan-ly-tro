<?php

namespace Database\Factories;

use App\Models\BoardingHouse;
use App\Models\Contract;
use App\Models\Landlord;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => function (): int {
                $owner = User::factory()->create();
                $landlord = Landlord::create(['user_id' => $owner->id, 'full_name' => $owner->name, 'phone' => '0900000000']);
                $house = BoardingHouse::create(['landlord_id' => $landlord->id, 'name' => fake()->company(), 'address' => fake()->address()]);

                return Room::create(['boarding_house_id' => $house->id, 'room_code' => '101', 'monthly_rent' => 3800000, 'status' => 'AVAILABLE'])->id;
            },
            'tenant_id' => fn (array $attributes) => Tenant::create(['landlord_id' => Room::findOrFail($attributes['room_id'])->boardingHouse->landlord_id, 'full_name' => fake()->name()])->id,
            'contract_code' => fake()->unique()->bothify('HD-########'),
            'start_date' => '2026-10-01', 'end_date' => '2027-09-30',
            'monthly_rent' => 3800000, 'deposit_amount' => 5000000, 'status' => 'DRAFT',
        ];
    }
}
