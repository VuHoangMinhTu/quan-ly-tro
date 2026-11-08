<?php

namespace Tests\Feature;

use App\Models\Contract;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ManualPaymentLedgerMigrationTest extends TestCase
{
    private const MIGRATION = '2026_11_08_210959_create_manual_payment_requests_table';

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    private function historicalDatabase(int $count = 12): array
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Requires isolated MySQL, not SQLite.');
        }
        $this->assertSame('testing', app()->environment());
        $this->assertSame('13368', (string) config('database.connections.mysql.port'));
        $this->assertSame('beehouse_billing_v2_test', DB::selectOne('SELECT DATABASE() AS name')->name);
        Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
        $migration = require database_path('migrations/'.self::MIGRATION.'.php');
        $migration->down();
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        $contract = Contract::factory()->create(['status' => 'ACTIVE']);
        $invoice = $contract->invoices()->create(['room_id' => $contract->room_id, 'billing_period' => '2026-11-01',
            'status' => 'PARTIALLY_PAID', 'total_amount' => 10000000, 'paid_amount' => $count * 1000]);
        $rows = [];
        for ($number = 0; $number < $count; $number++) {
            $keyed = $number % 4 === 1;
            $rows[] = ['invoice_id' => $invoice->id, 'amount' => 1000, 'paid_at' => '2026-10-01 10:00:00',
                'payment_method' => $number % 4 === 0 ? 'PAYOS' : 'CASH',
                'payment_source' => $number % 4 === 0 ? 'PAYOS_WEBHOOK' : 'MANUAL',
                'external_reference' => $number % 4 === 0 ? 'SYNTHETIC-'.$number : null,
                'reference_code' => 'LEGACY-LABEL', 'idempotency_key' => $keyed ? sprintf('00000000-0000-4000-8000-%012d', $number) : null,
                'idempotency_fingerprint' => $keyed ? hash('sha256', 'historically-stored-'.$number) : null,
                'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-01 10:00:00'];
        }
        foreach (array_chunk($rows, 250) as $batch) {
            DB::table('payments')->insert($batch);
        }

        return [$invoice, $migration, DB::table('payments')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
    }

    private function migrateTarget(): void
    {
        $this->assertSame(0, Artisan::call('migrate', ['--path' => 'database/migrations/'.self::MIGRATION.'.php', '--force' => true, '--no-interaction' => true]));
    }

    public function test_2000_historical_payments_are_unchanged_and_only_real_keys_are_backfilled(): void
    {
        [$invoice, $migration, $before] = $this->historicalDatabase(2000);
        $this->migrateTarget();

        $this->assertSame($before, DB::table('payments')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame('2000000.00', $invoice->fresh()->paid_amount);
        $this->assertSame('2000000.00', (string) DB::table('payments')->sum('amount'));
        $this->assertDatabaseCount('manual_payment_requests', 500);
        $this->assertSame([], Schema::getForeignKeys('manual_payment_requests'));
        $this->assertTrue(Schema::hasIndex('payments', ['payment_method', 'external_reference'], 'unique'));
        $this->assertTrue(Schema::hasIndex('payments', ['invoice_id', 'idempotency_key'], 'unique'));
        $snapshot = DB::table('manual_payment_requests')->orderBy('id')->get()->all();
        $migration->up();
        $this->assertEquals($snapshot, DB::table('manual_payment_requests')->orderBy('id')->get()->all());
    }

    #[TestWith(['ddl'])]
    #[TestWith(['index'])]
    #[TestWith(['backfill'])]
    public function test_resume_after_committed_ddl_or_partial_backfill_preserves_evidence(string $stage): void
    {
        [, , $before] = $this->historicalDatabase();
        $interrupted = false;
        DB::listen(function ($query) use ($stage, &$interrupted): void {
            if (! $interrupted && (($stage === 'ddl' && str_starts_with($query->sql, 'create table `manual_payment_requests`'))
                || ($stage === 'index' && str_starts_with($query->sql, 'alter table `manual_payment_requests`') && str_contains($query->sql, 'manual_payment_request_scope_unique'))
                || ($stage === 'backfill' && str_starts_with($query->sql, 'insert ignore into `manual_payment_requests`')))) {
                $interrupted = true;
                throw new \RuntimeException('Synthetic ledger migration interruption');
            }
        });
        try {
            $this->migrateTarget();
            $this->fail('Failure injection must interrupt the migration.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic ledger migration interruption', $exception->getMessage());
        }
        $this->assertTrue($interrupted);
        $this->assertSame(0, DB::table('migrations')->where('migration', self::MIGRATION)->count());

        $this->migrateTarget();

        $this->assertDatabaseCount('manual_payment_requests', 3);
        $this->assertSame($before, DB::table('payments')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame(1, DB::table('migrations')->where('migration', self::MIGRATION)->count());
    }

    public function test_invalid_existing_fingerprint_is_not_guessed_or_repaired(): void
    {
        [, $migration] = $this->historicalDatabase();
        $id = DB::table('payments')->whereNotNull('idempotency_key')->value('id');
        DB::table('payments')->where('id', $id)->update(['idempotency_fingerprint' => null]);
        try {
            $migration->up();
            $this->fail('Missing fingerprint must require reconciliation.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('incomplete ownership or replay evidence', $exception->getMessage());
        }
        $this->assertDatabaseHas('payments', ['id' => $id, 'idempotency_fingerprint' => null]);
        $this->assertDatabaseCount('manual_payment_requests', 0);
    }

    public function test_down_refuses_to_remove_a_backfilled_ledger(): void
    {
        [, $migration] = $this->historicalDatabase();
        $this->migrateTarget();
        try {
            $migration->down();
            $this->fail('Replay history must not be dropped.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('replay history exists', $exception->getMessage());
        }

        $this->assertDatabaseCount('manual_payment_requests', 3);
    }
}
