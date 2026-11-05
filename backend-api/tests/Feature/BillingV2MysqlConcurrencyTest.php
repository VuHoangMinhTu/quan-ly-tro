<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Room;
use App\Services\BillingV2Evidence;
use App\Services\MonthlyDraftRecalculation;
use App\Services\MonthlyInvoiceBilling;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class BillingV2MysqlConcurrencyTest extends TestCase
{
    #[TestWith(['utility'])]
    #[TestWith(['monthly'])]
    #[TestWith(['recalculate'])]
    public function test_simultaneous_mysql_workers_wait_on_room_lock_and_create_one_obligation(string $mode): void
    {
        $monthly = $mode !== 'utility';
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Requires the isolated MySQL configuration, never simulated with SQLite.');
        }
        $this->assertSame('127.0.0.1', config('database.connections.mysql.host'));
        $this->assertSame('13368', (string) config('database.connections.mysql.port'));
        $this->assertSame('beehouse_billing_v2_test', DB::selectOne('SELECT DATABASE() AS name')->name);
        $this->assertSame('billing_test', config('database.connections.mysql.username'));
        Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
        $contract = Contract::factory()->create(['status' => 'ACTIVE']);
        $room = $contract->room;
        $actor = $room->boardingHouse->landlord->user_id;
        $contract->handoverReports()->create(['type' => 'CHECK_IN', 'status' => 'CONFIRMED', 'items' => [], 'confirmed_at' => now()]);
        $service = $room->boardingHouse->services()->create(['name' => 'Synthetic Electricity', 'type' => 'ELECTRICITY', 'billing_method' => 'TIERED', 'base_price' => 0, 'is_active' => true]);
        $service->priceTiers()->createMany([['from_quantity' => 0, 'to_quantity' => 20, 'unit_price' => 2000, 'tier_order' => 1], ['from_quantity' => 20, 'to_quantity' => null, 'unit_price' => 4000, 'tier_order' => 2]]);
        $contract->service_terms_version = 1;
        $contract->save();
        $contract->agreedServices()->create(['service_id' => $service->id, 'service_name' => $service->name, 'service_type' => $service->type, 'billing_method' => 'TIERED', 'base_price' => 0, 'effective_from' => '2026-10-01', 'is_active' => true, 'pricing_snapshot' => $service->priceTiers->map->only(['from_quantity', 'to_quantity', 'unit_price', 'tier_order'])->all()]);
        $meter = $room->utilityMeters()->create(['service_id' => $service->id, 'is_active' => true]);
        foreach ([['PERIOD_BOUNDARY', null, 'P:2026-10-01 00:00:00', '2026-10-01 00:00:00', 100], ['CHECK_IN', $contract->id, 'CHECK_IN:'.$contract->id, '2026-10-01 00:00:00', 100], ['PERIOD_BOUNDARY', null, 'P:2026-11-01 00:00:00', '2026-11-01 00:00:00', 150]] as [$kind, $contractId, $scope, $at, $reading]) {
            DB::table('utility_checkpoints')->insert(['utility_meter_id' => $meter->id, 'contract_id' => $contractId, 'kind' => $kind, 'scope_key' => $scope, 'revision' => 1, 'observed_at' => $at, 'reading_value' => $reading, 'confirmed_by' => $actor, 'created_at' => now()]);
        }
        $token = '';
        if ($mode === 'recalculate') {
            app(MonthlyInvoiceBilling::class)->generate($room->boardingHouse, '2026-10-01', '2026-10-01 00:00:00', '2026-11-01 00:00:00', [], $actor);
            $invoice = $room->invoices()->firstOrFail();
            $invoice->items()->create(['type' => 'OTHER', 'description' => 'Synthetic manual retained', 'quantity' => 1, 'unit_price' => 5000, 'amount' => 5000, 'source' => 'MANUAL']);
            $invoice->recalculateTotals();
            app(BillingV2Evidence::class)->checkpoint($meter, ['kind' => 'PERIOD_BOUNDARY', 'observed_at' => '2026-11-01 00:00:00', 'reading_value' => 160], $actor);
            $preview = app(MonthlyDraftRecalculation::class)->preview($room, '2026-10-01', '2026-10-01 00:00:00', '2026-11-01 00:00:00', [], $actor);
            $this->assertSame('PREVIEWED', $preview['status']);
            $token = $preview['token'];
        }
        $directory = dirname(base_path()).'/tmp/billing-v2-mysql/concurrency-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $workers = [];
        DB::beginTransaction();
        Room::whereKey($room->id)->lockForUpdate()->firstOrFail();
        try {
            foreach ([1, 2] as $number) {
                $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/BillingV2ConcurrentWorker.php'), (string) $room->id, $directory, (string) $number, $mode, $token], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $workers[] = [$process, $pipes];
            }
            $this->waitForFiles([$directory.'/ready-1', $directory.'/ready-2']);
            file_put_contents($directory.'/go', 'go');
            $this->waitForFiles([$directory.'/attempting-1', $directory.'/attempting-2']);
            usleep(200000);
            foreach ($workers as [$process]) {
                $this->assertTrue(proc_get_status($process)['running'], 'Both real processes must wait while the parent holds the room row lock.');
            }
            DB::commit();
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $deadline = microtime(true) + 20;
                do {
                    $status = proc_get_status($process);
                    if (microtime(true) > $deadline) {
                        $this->fail('Concurrent billing worker timed out.');
                    }
                    usleep(10000);
                } while ($status['running']);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                $this->assertSame(0, $status['exitcode'], $stderr);
                $results[] = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertNotSame($results[0]['connection_id'], $results[1]['connection_id']);
            $this->assertSame($results[0]['invoice_ids'], $results[1]['invoice_ids']);
            $this->assertDatabaseCount('invoices', 1);
            $this->assertDatabaseCount('invoice_obligations', 1);
            $this->assertDatabaseCount('invoice_items', $mode === 'recalculate' ? 3 : ($monthly ? 2 : 1));
            $this->assertDatabaseCount('monthly_invoice_compositions', $monthly ? 1 : 0);
            $this->assertDatabaseCount('utility_charge_allocations', 1);
            $this->assertDatabaseHas('invoice_items', ['quantity' => $mode === 'recalculate' ? 60 : 50, 'amount' => $mode === 'recalculate' ? 200000 : 160000]);
            if ($mode === 'recalculate') {
                $this->assertEqualsCanonicalizing([false, true], array_column($results, 'replayed'));
                $this->assertDatabaseCount('monthly_draft_recalculations', 1);
                $this->assertDatabaseHas('monthly_draft_recalculations', ['status' => 'APPLIED']);
                $this->assertDatabaseHas('invoice_items', ['description' => 'Synthetic manual retained', 'amount' => 5000]);
            }
        } finally {
            RefreshDatabaseState::$migrated = false;
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as [$process, $pipes]) {
                if (proc_get_status($process)['running']) {
                    proc_terminate($process);
                }
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
            }
        }
    }

    private function waitForFiles(array $paths): void
    {
        $deadline = microtime(true) + 15;
        while (count(array_filter($paths, 'is_file')) !== count($paths)) {
            if (microtime(true) > $deadline) {
                $this->fail('Concurrency barrier timed out.');
            }
            clearstatcache();
            usleep(10000);
        }
    }
}
