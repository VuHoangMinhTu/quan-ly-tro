<?php

namespace Tests\Feature;

use App\Models\BoardingHouse;
use App\Models\Contract;
use App\Models\RoomTenant;
use App\Services\BoardingHouseAccessService;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Performance\DashboardServiceBefore;
use Tests\TestCase;

class DashboardPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_query_budget_and_financial_output_with_synthetic_houses(): void
    {
        config(['app.timezone' => 'Asia/Ho_Chi_Minh']);
        $this->travelTo('2026-10-16 12:00:00');
        $contract = Contract::factory()->create(['status' => 'ACTIVE', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31']);
        $house = $contract->room->boardingHouse;
        $user = $house->landlord->user;
        RoomTenant::create(['room_id' => $contract->room_id, 'tenant_id' => $contract->tenant_id, 'status' => 'ACTIVE', 'move_in_date' => '2026-10-01']);
        $houses = collect([$house]);
        for ($index = 1; $index < 10; $index++) {
            $houses->push(BoardingHouse::create(['landlord_id' => $house->landlord_id, 'name' => 'Synthetic '.$index, 'address' => 'Test only']));
        }
        foreach ($houses as $current) {
            for ($index = $current->id === $house->id ? 1 : 0; $index < 10; $index++) {
                $current->rooms()->create(['room_code' => 'TEST-'.$index, 'monthly_rent' => null, 'status' => 'AVAILABLE']);
            }
            foreach ($current->rooms()->get() as $room) {
                $invoice = $room->invoices()->create([
                    'contract_id' => $room->id === $contract->room_id ? $contract->id : null,
                    'invoice_code' => 'TEST-'.$room->id, 'billing_period' => '2026-10-01', 'due_date' => '2026-10-15',
                    'status' => 'PARTIALLY_PAID', 'subtotal' => 1000, 'discount_amount' => 0, 'total_amount' => 1000, 'paid_amount' => 100,
                ]);
                $invoice->payments()->create(['amount' => 100, 'payment_method' => 'CASH', 'paid_at' => '2026-10-10']);
            }
        }
        $this->withToken($user->createToken('synthetic-dashboard-metrics')->plainTextToken);
        $implementations = ['after' => app(DashboardService::class)];
        if (getenv('DASHBOARD_COMPARE_BASELINE')) {
            require_once __DIR__.'/../Fixtures/Performance/DashboardServiceBefore.php';
            $implementations = ['before' => new DashboardServiceBefore(app(BoardingHouseAccessService::class)), ...$implementations];
        }
        $samples = [];
        for ($sample = 0; $sample < 8; $sample++) {
            foreach ($sample % 2 === 0 ? $implementations : array_reverse($implementations, true) as $phase => $implementation) {
                $this->app->instance(DashboardService::class, $implementation);
                $this->app['auth']->forgetGuards();
                DB::enableQueryLog();
                DB::flushQueryLog();
                $started = hrtime(true);
                $response = $this->getJson('/api/dashboard');
                $elapsed = (hrtime(true) - $started) / 1_000_000;
                $queries = DB::getQueryLog();
                DB::disableQueryLog();
                $response->assertOk()->assertJsonPath('data.summary.total_boarding_houses', 10)
                    ->assertJsonPath('data.summary.total_rooms', 100)->assertJsonPath('data.invoice_summary.invoice_total', '100000.00')
                    ->assertJsonPath('data.revenue_chart.5.amount', '10000.00')->assertJsonCount(8, 'data.recent_unpaid_invoices');
                $this->assertLessThanOrEqual($sample === 0 ? 30 : ($phase === 'before' ? 18 : 17), count($queries));
                $this->assertCount($phase === 'before' ? 1 : 0, array_filter($queries, fn (array $query): bool => preg_match('/select "id", "name" from "boarding_houses"/i', $query['query']) === 1));
                if ($sample > 0) {
                    $samples[$phase][] = [
                        'requestMs' => round($elapsed, 3), 'queryCount' => count($queries),
                        'sqlTimeMs' => round(array_sum(array_column($queries, 'time')), 3),
                        'houseNameReloads' => count(array_filter($queries, fn (array $query): bool => preg_match('/select "id", "name" from "boarding_houses"/i', $query['query']) === 1)),
                    ];
                }
            }
        }
        if (getenv('DASHBOARD_METRICS')) {
            fwrite(STDERR, json_encode(['kind' => 'dashboard-kernel-sqlite', 'phase' => getenv('DASHBOARD_METRICS'), 'paired' => count($implementations) === 2, 'dataset' => ['houses' => 10, 'rooms' => 100, 'invoices' => 100, 'payments' => 100], 'warmup' => 1, 'samples' => $samples], JSON_THROW_ON_ERROR).PHP_EOL);
        }
    }
}
