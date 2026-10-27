<?php

namespace Tests\Feature;

use App\Models\BoardingHouse;
use App\Models\Invoice;
use App\Models\Landlord;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class BoardingHouseWithRoomsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_creation_requires_authentication(): void
    {
        $this->postJson('/api/boarding-houses/with-rooms', $this->payload())->assertUnauthorized();
        $this->assertDatabaseCount('boarding_houses', 0);
    }

    public function test_house_and_rooms_can_be_created_with_unconfigured_optional_fields(): void
    {
        $landlord = $this->owner();
        $response = $this->actingAsOwner($landlord)->postJson('/api/boarding-houses/with-rooms', $this->payload())
            ->assertCreated()->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Đã tạo nhà trọ và 2 phòng thành công.')
            ->assertJsonPath('data.rooms_count', 2);

        $id = $response->json('data.id');
        $this->assertDatabaseHas('boarding_houses', ['id' => $id, 'landlord_id' => $landlord->id]);
        $this->assertDatabaseCount('rooms', 2);
        foreach (['101', '102'] as $code) {
            $this->assertDatabaseHas('rooms', ['boarding_house_id' => $id, 'room_code' => $code, 'monthly_rent' => null, 'area' => null, 'max_tenants' => null, 'status' => 'AVAILABLE']);
        }
        $this->getJson("/api/boarding-houses/{$id}/rooms")->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.monthly_rent', null);
        $this->actingAsOwner($this->owner())->getJson("/api/boarding-houses/{$id}")->assertNotFound();
    }

    public function test_mixed_prices_and_zero_are_distinct_from_null(): void
    {
        $data = $this->payload();
        $data['rooms'] = [
            ['room_code' => '101', 'area' => 20.5, 'monthly_rent' => 3800000, 'max_tenants' => 2],
            ['room_code' => '102', 'area' => null, 'monthly_rent' => null, 'max_tenants' => null],
            ['room_code' => '103', 'area' => 0, 'monthly_rent' => 0, 'max_tenants' => 1],
        ];
        $this->actingAsOwner($this->owner())->postJson('/api/boarding-houses/with-rooms', $data)->assertCreated();

        $this->assertDatabaseHas('rooms', ['room_code' => '101', 'area' => 20.5, 'monthly_rent' => 3800000, 'max_tenants' => 2]);
        $this->assertDatabaseHas('rooms', ['room_code' => '102', 'area' => null, 'monthly_rent' => null, 'max_tenants' => null]);
        $this->assertDatabaseHas('rooms', ['room_code' => '103', 'area' => 0, 'monthly_rent' => 0]);
    }

    public function test_duplicate_codes_are_rejected_before_any_write_with_422(): void
    {
        $data = $this->payload();
        $data['rooms'] = [['room_code' => ' p101 '], ['room_code' => 'P101']];
        $this->actingAsOwner($this->owner())->postJson('/api/boarding-houses/with-rooms', $data)
            ->assertUnprocessable()->assertJsonValidationErrors(['rooms.0.room_code', 'rooms.1.room_code'])
            ->assertJson(['errors' => ['rooms.1.room_code' => ['Mã phòng bị trùng trong danh sách.']]]);
        $this->assertDatabaseCount('boarding_houses', 0);
        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_invalid_optional_fields_and_empty_batch_return_422_without_writes(): void
    {
        $this->actingAsOwner($this->owner());
        $data = $this->payload();
        $data['rooms'] = [['room_code' => '', 'area' => -1, 'monthly_rent' => -1, 'max_tenants' => 1.5]];
        $this->postJson('/api/boarding-houses/with-rooms', $data)->assertUnprocessable()
            ->assertJsonValidationErrors(['rooms.0.room_code', 'rooms.0.area', 'rooms.0.monthly_rent', 'rooms.0.max_tenants']);
        $data['rooms'] = [];
        $this->postJson('/api/boarding-houses/with-rooms', $data)->assertUnprocessable()->assertJsonValidationErrors('rooms');
        $data['rooms'] = array_fill(0, 201, ['room_code' => '101']);
        $this->postJson('/api/boarding-houses/with-rooms', $data)->assertUnprocessable()->assertJsonValidationErrors('rooms');
        $this->assertDatabaseCount('boarding_houses', 0);
    }

    public function test_client_cannot_assign_ownership_keys(): void
    {
        $landlord = $this->owner();
        $other = $this->owner();
        $data = $this->payload();
        $data['boarding_house']['landlord_id'] = $other->id;
        $this->actingAsOwner($landlord)->postJson('/api/boarding-houses/with-rooms', $data)
            ->assertUnprocessable()->assertJsonValidationErrors('boarding_house');
        unset($data['boarding_house']['landlord_id']);
        $data['rooms'][0]['boarding_house_id'] = 9999;
        $this->postJson('/api/boarding-houses/with-rooms', $data)->assertUnprocessable()->assertJsonValidationErrors('rooms.0');
        $this->assertDatabaseCount('boarding_houses', 0);
    }

    public function test_failure_on_second_room_rolls_back_house_and_first_room(): void
    {
        $this->actingAsOwner($this->owner());
        Event::listen('eloquent.creating: '.Room::class, function (Room $room): void {
            if ($room->room_code === '102') {
                throw new RuntimeException('Simulated storage failure on second room.');
            }
        });

        try {
            $data = $this->payload();
            $data['floors'] = [['client_key' => 'ground', 'name' => 'Tầng trệt', 'sort_order' => 0]];
            $data['rooms'][0]['floor_client_key'] = 'ground';
            $this->postJson('/api/boarding-houses/with-rooms', $data)->assertStatus(500);
            $this->assertDatabaseCount('boarding_houses', 0);
            $this->assertDatabaseCount('floors', 0);
            $this->assertDatabaseCount('rooms', 0);
        } finally {
            Event::forget('eloquent.creating: '.Room::class);
        }
    }

    public function test_temporary_floors_map_to_new_house_and_room_list_preserves_floor_groups(): void
    {
        $landlord = $this->owner();
        $data = $this->payload();
        $data['floors'] = [
            ['client_key' => 'ground', 'name' => 'Tầng trệt', 'sort_order' => 0],
            ['client_key' => 'first', 'name' => 'Tầng 1', 'sort_order' => 1],
            ['client_key' => 'roof', 'name' => 'Sân thượng', 'sort_order' => 2],
        ];
        $data['rooms'] = array_map(fn (int $code) => ['room_code' => (string) $code, 'floor_client_key' => 'first', 'room_type' => 'PRIVATE_ROOM', 'private_room_type' => 'STUDIO'], range(101, 110));
        $data['rooms'][4]['floor_client_key'] = 'ground';
        $data['rooms'][8] = ['room_code' => '109', 'floor_client_key' => 'roof', 'room_type' => 'DORMITORY', 'private_room_type' => 'LOFT'];
        $data['rooms'][9]['floor_client_key'] = null;
        $id = $this->actingAsOwner($landlord)->postJson('/api/boarding-houses/with-rooms', $data)->assertCreated()->assertJsonPath('data.rooms_count', 10)->json('data.id');
        $house = BoardingHouse::findOrFail($id);
        $floors = $house->floors()->pluck('id', 'name');
        $this->assertDatabaseCount('floors', 3);
        $this->assertDatabaseCount('rooms', 10);
        $this->assertDatabaseHas('floors', ['id' => $floors['Tầng trệt'], 'boarding_house_id' => $id, 'sort_order' => 0]);
        $this->assertDatabaseHas('rooms', ['boarding_house_id' => $id, 'room_code' => '101', 'floor_id' => $floors['Tầng 1'], 'room_type' => 'PRIVATE_ROOM', 'private_room_type' => 'STUDIO']);
        $this->assertDatabaseHas('rooms', ['boarding_house_id' => $id, 'room_code' => '105', 'floor_id' => $floors['Tầng trệt']]);
        $this->assertDatabaseHas('rooms', ['boarding_house_id' => $id, 'room_code' => '109', 'floor_id' => $floors['Sân thượng'], 'room_type' => 'DORMITORY', 'private_room_type' => null]);
        $this->assertDatabaseHas('rooms', ['boarding_house_id' => $id, 'room_code' => '110', 'floor_id' => null]);
        $this->getJson("/api/boarding-houses/{$id}/rooms")->assertOk()
            ->assertJsonPath('data.0.room_code', '105')->assertJsonPath('data.0.floor_id', $floors['Tầng trệt'])
            ->assertJsonPath('data.0.floor.name', 'Tầng trệt')->assertJsonPath('data.0.floor.sort_order', 0)
            ->assertJsonPath('data.9.room_code', '110');
        $this->actingAsOwner($this->owner())->getJson("/api/boarding-houses/{$id}/floors")->assertNotFound();
    }

    public function test_invalid_floor_references_duplicates_and_foreign_ids_return_422_without_partial_writes(): void
    {
        $otherHouse = $this->house($this->owner());
        $foreignFloor = $otherHouse->floors()->create(['name' => 'Tầng ngoài workspace', 'sort_order' => 0]);
        $this->actingAsOwner($this->owner());
        $data = $this->payload();
        $data['rooms'][0]['floor_id'] = $foreignFloor->id;
        $this->postJson('/api/boarding-houses/with-rooms', $data)->assertUnprocessable()->assertJsonValidationErrors('rooms.0');
        unset($data['rooms'][0]['floor_id']);
        $data['rooms'][0]['floor_client_key'] = (string) $foreignFloor->id;
        $this->postJson('/api/boarding-houses/with-rooms', $data)->assertUnprocessable()->assertJsonValidationErrors('rooms.0.floor_client_key');
        $data['rooms'][0]['floor_client_key'] = 'a';
        $data['floors'] = [
            ['client_key' => 'a', 'name' => ' Trệt ', 'sort_order' => 0],
            ['client_key' => 'a', 'name' => 'trệt', 'sort_order' => -1],
        ];
        $this->postJson('/api/boarding-houses/with-rooms', $data)->assertUnprocessable()
            ->assertJsonValidationErrors(['floors.0.client_key', 'floors.1.name', 'floors.1.sort_order'])
            ->assertJsonFragment(['Tên tầng bị trùng trong danh sách.']);
        $data['floors'] = [['client_key' => 'a', 'name' => '  ', 'sort_order' => 0]];
        $this->postJson('/api/boarding-houses/with-rooms', $data)->assertUnprocessable()->assertJsonValidationErrors('floors.0.name');
        $this->assertDatabaseCount('boarding_houses', 1);
        $this->assertDatabaseCount('floors', 1);
        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_single_room_create_and_update_accept_null_rent_and_preserve_zero(): void
    {
        $landlord = $this->owner();
        $house = $this->house($landlord);
        $this->actingAsOwner($landlord);
        $response = $this->postJson("/api/boarding-houses/{$house->id}/rooms", ['room_code' => '101', 'status' => 'AVAILABLE'])
            ->assertCreated()->assertJsonPath('data.monthly_rent', null);
        $id = $response->json('data.id');
        $this->assertDatabaseHas('rooms', ['id' => $id, 'monthly_rent' => null]);
        $this->putJson("/api/rooms/{$id}", ['room_code' => '101', 'monthly_rent' => 0, 'status' => 'AVAILABLE'])->assertOk();
        $this->assertDatabaseHas('rooms', ['id' => $id, 'monthly_rent' => 0]);
        $this->putJson("/api/rooms/{$id}", ['room_code' => '101', 'monthly_rent' => null, 'status' => 'AVAILABLE'])->assertOk();
        $this->assertDatabaseHas('rooms', ['id' => $id, 'monthly_rent' => null]);
    }

    public function test_contract_agreed_rent_is_independent_of_unconfigured_room_rent_and_zero_is_valid(): void
    {
        $landlord = $this->owner();
        $room = $this->house($landlord)->rooms()->create(['room_code' => '101', 'monthly_rent' => null, 'status' => 'AVAILABLE']);
        $tenant = Tenant::create(['landlord_id' => $landlord->id, 'full_name' => 'Nguyễn Văn A']);
        $payload = $this->contractPayload($tenant);
        $this->actingAsOwner($landlord)->postJson("/api/rooms/{$room->id}/contracts", $payload)
            ->assertCreated();
        $this->assertDatabaseHas('contracts', ['room_id' => $room->id, 'monthly_rent' => 2500000]);
        $room->update(['monthly_rent' => 0]);
        $payload['contract_code'] = 'HD102';
        $this->postJson("/api/rooms/{$room->id}/contracts", $payload)->assertCreated();
        $this->assertDatabaseHas('contracts', ['room_id' => $room->id, 'monthly_rent' => 2500000]);
    }

    public function test_invoice_without_contract_does_not_invent_zero_rent_for_unconfigured_room(): void
    {
        $landlord = $this->owner();
        $room = $this->house($landlord)->rooms()->create(['room_code' => '101', 'status' => 'AVAILABLE']);
        $this->actingAsOwner($landlord)->postJson("/api/rooms/{$room->id}/invoices", [
            'invoice_code' => 'INV-101', 'billing_period' => '2026-10-01', 'status' => 'DRAFT',
        ])->assertCreated();
        $invoice = Invoice::query()->sole();
        $this->postJson("/api/invoices/{$invoice->id}/generate")->assertOk();
        $this->assertDatabaseMissing('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'RENT']);
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'monthly_rent' => null]);
        $this->getJson("/api/invoices/{$invoice->id}")->assertOk()->assertJsonPath('data.room.monthly_rent', null);
    }

    public function test_existing_contract_snapshot_still_updates_and_bills_if_room_rent_is_cleared(): void
    {
        $landlord = $this->owner();
        $room = $this->house($landlord)->rooms()->create(['room_code' => '101', 'status' => 'AVAILABLE']);
        $tenant = Tenant::create(['landlord_id' => $landlord->id, 'full_name' => 'Nguyễn Văn A']);
        $payload = $this->contractPayload($tenant);
        $contract = $room->contracts()->create($payload);
        $this->actingAsOwner($landlord)->putJson("/api/contracts/{$contract->id}", $payload)->assertOk();
        $invoice = $room->invoices()->create([
            'contract_id' => $contract->id, 'invoice_code' => 'INV-101', 'billing_period' => '2026-10-01',
            'subtotal' => 0, 'discount_amount' => 0, 'total_amount' => 0, 'paid_amount' => 0, 'status' => 'DRAFT',
        ]);
        $this->postJson("/api/invoices/{$invoice->id}/generate")->assertOk();
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'type' => 'RENT', 'amount' => 2500000]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'total_amount' => 2500000]);
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'monthly_rent' => null]);
    }

    private function owner(): Landlord
    {
        $user = User::factory()->create();

        return Landlord::create(['user_id' => $user->id, 'full_name' => $user->name, 'phone' => '0900000000']);
    }

    private function actingAsOwner(Landlord $landlord): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($landlord->user->createToken('test')->plainTextToken);
    }

    private function house(Landlord $landlord): BoardingHouse
    {
        return $landlord->boardingHouses()->create(['name' => 'BeeHouse', 'address' => 'Dĩ An']);
    }

    private function payload(): array
    {
        return [
            'boarding_house' => ['name' => 'BeeHouse', 'address' => 'Dĩ An', 'description' => null],
            'rooms' => [['room_code' => '101'], ['room_code' => '102', 'area' => null, 'monthly_rent' => null, 'max_tenants' => null]],
        ];
    }

    private function contractPayload(Tenant $tenant): array
    {
        return [
            'tenant_id' => $tenant->id, 'contract_code' => 'HD101', 'start_date' => '2026-09-01',
            'end_date' => '2027-09-01', 'monthly_rent' => 2500000, 'deposit_amount' => 0, 'status' => 'DRAFT',
        ];
    }
}
