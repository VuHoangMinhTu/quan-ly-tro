<?php

namespace Tests\Feature;

use App\Models\Landlord;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoomCreatePerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_room_query_growth_with_a_synthetic_five_thousand_room_dataset(): void
    {
        $owner = $this->owner();
        $this->seedOtherLandlords(100, 50);
        $this->actingAsOwner($owner);

        $metrics = [
            'create_1' => $this->measureSingle($owner),
            'bulk_10' => $this->measureBulk($owner, 10),
            'bulk_50' => $this->measureBulk($owner, 50),
            'bulk_100' => $this->measureBulk($owner, 100),
        ];

        foreach ($metrics as $metric) {
            $this->assertSame(1, $metric['requests']);
            $this->assertSame(0, $metric['errors']);
        }
        $this->assertLessThanOrEqual($metrics['bulk_10']['selects'] + 1, $metrics['bulk_100']['selects']);

        if (getenv('ROOM_CREATE_METRICS')) {
            fwrite(STDERR, json_encode([
                'kind' => 'room-create-kernel-sqlite',
                'dataset' => ['other_landlords' => 100, 'rooms_per_landlord' => 50, 'existing_rooms' => 5000],
                'metrics' => $metrics,
            ], JSON_THROW_ON_ERROR).PHP_EOL);
        }
    }

    /** @return array{requests: int, errors: int, requestMs: float, queries: int, selects: int, inserts: int, transactions: int} */
    private function measureSingle(Landlord $owner): array
    {
        $house = $owner->boardingHouses()->create(['name' => 'Single room performance house', 'address' => 'Synthetic only']);

        return $this->measure(fn () => $this->postJson("/api/boarding-houses/{$house->id}/rooms", [
            'room_code' => 'PERF-SINGLE', 'room_type' => 'PRIVATE_ROOM', 'private_room_type' => 'NO_LOFT',
            'status' => 'AVAILABLE', 'area' => 20, 'monthly_rent' => 3000000, 'max_tenants' => 2,
        ]));
    }

    /** @return array{requests: int, errors: int, requestMs: float, queries: int, selects: int, inserts: int, transactions: int} */
    private function measureBulk(Landlord $owner, int $count): array
    {
        $house = $owner->boardingHouses()->create(['name' => "Bulk {$count} performance house", 'address' => 'Synthetic only']);
        $rooms = array_map(fn (int $number): array => [
            'room_code' => "PERF-{$count}-{$number}",
            'room_type' => $number % 10 === 0 ? 'DORMITORY' : 'PRIVATE_ROOM',
            'private_room_type' => $number % 10 === 0 ? null : 'NO_LOFT',
            'area' => 20, 'monthly_rent' => 3000000, 'max_tenants' => 2,
        ], range(1, $count));

        return $this->measure(fn () => $this->postJson("/api/boarding-houses/{$house->id}/rooms/bulk", ['rooms' => $rooms]));
    }

    /** @return array{requests: int, errors: int, requestMs: float, queries: int, selects: int, inserts: int, transactions: int} */
    private function measure(callable $request): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = hrtime(true);
        $response = $request();
        $elapsed = (hrtime(true) - $started) / 1_000_000;
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertCreated();
        $statements = array_map(fn (array $query): string => strtolower(ltrim($query['query'])), $queries);

        return [
            'requests' => 1, 'errors' => 0, 'requestMs' => round($elapsed, 3), 'queries' => count($queries),
            'selects' => count(array_filter($statements, fn (string $sql): bool => str_starts_with($sql, 'select'))),
            'inserts' => count(array_filter($statements, fn (string $sql): bool => str_starts_with($sql, 'insert'))),
            'transactions' => count(array_filter($statements, fn (string $sql): bool => str_starts_with($sql, 'begin') || str_starts_with($sql, 'savepoint'))),
        ];
    }

    private function seedOtherLandlords(int $landlords, int $roomsPerLandlord): void
    {
        for ($landlordNumber = 1; $landlordNumber <= $landlords; $landlordNumber++) {
            $landlord = $this->owner();
            $house = $landlord->boardingHouses()->create(['name' => "Synthetic {$landlordNumber}", 'address' => 'Synthetic only']);
            for ($roomNumber = 1; $roomNumber <= $roomsPerLandlord; $roomNumber++) {
                Room::create(['boarding_house_id' => $house->id, 'room_code' => "S{$landlordNumber}-{$roomNumber}", 'status' => 'AVAILABLE']);
            }
        }
    }

    private function owner(): Landlord
    {
        $user = User::factory()->create();

        return Landlord::create(['user_id' => $user->id, 'full_name' => $user->name, 'phone' => '+8490'.str_pad((string) User::query()->count(), 7, '0', STR_PAD_LEFT)]);
    }

    private function actingAsOwner(Landlord $landlord): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($landlord->user->createToken('performance-test')->plainTextToken);
    }
}
