<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_scope_revocation_and_select_only_requests(): void
    {
        $this->getJson('/api/dashboard/summary')->assertUnauthorized();
        $contract = Contract::factory()->create(['status' => 'ACTIVE', 'start_date' => '2024-02-01', 'end_date' => '2024-03-07']);
        $house = $contract->room->boardingHouse;
        $id = $house->id;
        $owner = $house->landlord->user;
        $manager = User::factory()->create();
        $house->managers()->attach($manager);
        $token = $owner->createToken('analytics')->plainTextToken;
        CarbonImmutable::setTestNow('2024-02-29 12:00:00+07:00');
        try {
            DB::enableQueryLog();
            $this->withToken($token)->getJson("/api/dashboard/summary?boarding_house_id=$id")->assertOk()
                ->assertJsonPath('data.rooms.total', 1)
                ->assertJsonPath('data.contracts.expiry_buckets.0.count', 1);
            $this->app['auth']->forgetGuards();
            $this->getJson("/api/dashboard/analytics?boarding_house_id=$id&preset=day&anchor_date=2024-02-29&compare=1")
                ->assertOk()->assertJsonPath('data.period.to_exclusive', '2024-03-01')
                ->assertJsonPath('data.previous.from', '2024-02-28')
                ->assertJsonPath('data.series.0.cash_received', '0.00');
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertLessThanOrEqual(20, count($queries));
            foreach ($queries as $query) {
                $this->assertMatchesRegularExpression('/^select\b/i', $query['query']);
            }
            $this->app['auth']->forgetGuards();
            $this->withToken($manager->createToken('analytics')->plainTextToken)
                ->getJson("/api/dashboard/summary?boarding_house_id=$id")->assertOk()
                ->assertJsonPath('data.houses.0.operational_role', 'MANAGER');
            $house->managers()->detach($manager);
            $this->app['auth']->forgetGuards();
            $this->getJson("/api/dashboard/summary?boarding_house_id=$id")->assertNotFound();
            $this->app['auth']->forgetGuards();
            $this->getJson("/api/dashboard/analytics?boarding_house_id=$id")->assertNotFound();
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_billing_cohort_and_actual_payment_have_independent_dates(): void
    {
        config(['app.timezone' => 'Asia/Ho_Chi_Minh']);
        $contract = Contract::factory()->create();
        $room = $contract->room;
        $invoice = $room->invoices()->create([
            'invoice_code' => 'ANALYTICS-1', 'billing_period' => '2025-12-01', 'status' => 'PARTIALLY_PAID',
            'subtotal' => '25.70', 'total_amount' => '25.70', 'paid_amount' => '15.30',
        ]);
        foreach (['10.10', '5.20'] as $amount) {
            $invoice->payments()->create(['amount' => $amount, 'payment_method' => 'CASH', 'paid_at' => '2026-01-01 00:00:00']);
        }
        $owner = $room->boardingHouse->landlord->user;
        $this->withToken($owner->createToken('analytics')->plainTextToken)
            ->getJson('/api/dashboard/analytics?preset=custom&from=2025-12-01&to=2026-01-01&group_by=month')
            ->assertOk()->assertJsonPath('data.series.0.invoice_due_current', '10.40')
            ->assertJsonPath('data.series.0.invoice_paid_current', '15.30')
            ->assertJsonPath('data.series.0.cash_received', '0.00')
            ->assertJsonPath('data.series.1.cash_received', '15.30')
            ->assertJsonPath('data.series.1.invoice_value', '0.00');
    }

    public function test_conflicting_and_invalid_filters_are_rejected(): void
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('analytics')->plainTextToken);
        foreach (['preset=bogus', 'preset=today&anchor_date=2024-02-29', 'preset=day&anchor_date=2025-02-29',
            'preset=custom&from=2026-02-01&to=2026-01-01', 'group_by=hour', 'compare=2', 'boarding_house_id[]=1',
            'preset=custom&from=2024-01-01&to=2025-01-03', 'preset=custom&from=2024-01-01', 'unknown=1'] as $query) {
            $this->getJson('/api/dashboard/analytics?'.$query)->assertUnprocessable();
        }
        $this->getJson('/api/dashboard/summary?preset=month')->assertUnprocessable();
    }

    public function test_calendar_groups_and_previous_period_align_to_vietnam_dates(): void
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('analytics')->plainTextToken);
        CarbonImmutable::setTestNow('2024-03-01 00:05:00+07:00');
        try {
            foreach ([
                ['today', '2024-03-01', '2024-03-02', 'day', 1],
                ['yesterday', '2024-02-29', '2024-03-01', 'day', 1],
                ['last_7_days', '2024-02-24', '2024-03-02', 'day', 7],
                ['last_30_days', '2024-02-01', '2024-03-02', 'day', 30],
                ['week', '2024-02-26', '2024-03-04', 'week', 1],
                ['month', '2024-03-01', '2024-04-01', 'month', 1],
                ['quarter', '2024-01-01', '2024-04-01', 'quarter', 1],
                ['year', '2024-01-01', '2025-01-01', 'year', 1],
            ] as [$preset, $from, $to, $group, $count]) {
                $this->getJson("/api/dashboard/analytics?preset=$preset&group_by=$group&compare=1")
                    ->assertOk()
                    ->assertJsonPath('data.period.from', $from)
                    ->assertJsonPath('data.period.to_exclusive', $to)
                    ->assertJsonPath('data.previous.to_exclusive', $from)
                    ->assertJsonCount($count, 'data.series');
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_all_house_analytics_keeps_query_count_constant(): void
    {
        $contract = Contract::factory()->create();
        $owner = $contract->room->boardingHouse->landlord->user;
        $landlord = $contract->room->boardingHouse->landlord;
        $this->withToken($owner->createToken('analytics')->plainTextToken);
        $measure = function (): array {
            $this->app['auth']->forgetGuards();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/dashboard/analytics?preset=custom&from=2024-01-01&to=2024-12-31&group_by=day')
                ->assertOk()->assertJsonCount(366, 'data.series');
            $queries = DB::getQueryLog();
            DB::disableQueryLog();

            return $queries;
        };
        $single = $measure();
        for ($i = 0; $i < 20; $i++) {
            $house = $landlord->boardingHouses()->create(['name' => 'Test '.$i, 'address' => 'Test']);
            $house->rooms()->create(['room_code' => '101', 'monthly_rent' => 1000000, 'status' => 'AVAILABLE']);
        }
        $multiple = $measure();
        $this->assertCount(count($single), $multiple);
        $this->assertLessThanOrEqual(7, count($multiple));
        if (DB::getDriverName() === 'mysql') {
            $financial = array_filter($multiple, fn ($query) => str_contains($query['query'], ' AS cash_received')
                || str_contains($query['query'], ' AS invoice_due_current'));
            $this->assertCount(2, $financial);
            foreach ($financial as $query) {
                $plan = DB::select('EXPLAIN '.$query['query'], $query['bindings']);
                $this->assertNotEmpty($plan);
                if (getenv('DASHBOARD_PRINT_PLAN')) {
                    fwrite(STDERR, json_encode(['queries' => count($multiple), 'plan' => $plan]).PHP_EOL);
                }
            }
        }
    }
}
