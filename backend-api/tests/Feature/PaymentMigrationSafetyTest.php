<?php

namespace Tests\Feature;

use App\Models\Contract;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PaymentMigrationSafetyTest extends TestCase
{
    private const MIGRATION = '2026_11_08_063357_add_idempotency_to_manual_payments';

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    private function historicalDatabase(int $count = 4): array
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
        $invoice = $contract->invoices()->create(['room_id' => $contract->room_id,
            'billing_period' => '2026-11-01', 'status' => 'PARTIALLY_PAID',
            'total_amount' => 10000000, 'paid_amount' => $count * 1000]);
        $rows = [];
        for ($index = 0; $index < $count; $index++) {
            $payos = $index % 4 === 0;
            $rows[] = ['invoice_id' => $invoice->id, 'amount' => 1000,
                'payment_method' => $payos ? 'PAYOS' : ($index % 2 ? 'CASH' : 'BANK_TRANSFER'),
                'payment_source' => $payos ? 'PAYOS_WEBHOOK' : 'MANUAL',
                'external_reference' => $payos ? 'SYNTHETIC-PAYOS-'.$index : null,
                'reference_code' => 'LEGACY-REPEATED-LABEL', 'paid_at' => '2026-10-01 10:00:00',
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

    public function test_upgrade_preserves_2000_historical_receipts_and_both_unique_index_scopes(): void
    {
        [$invoice, , $before] = $this->historicalDatabase(2000);
        $columns = array_keys($before[0]);

        $this->migrateTarget();

        $this->assertSame($before, DB::table('payments')->select($columns)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame('2000000.00', $invoice->fresh()->paid_amount);
        $this->assertSame('2000000.00', (string) DB::table('payments')->sum('amount'));
        $this->assertSame(0, DB::table('payments')->whereNotNull('idempotency_key')->count());
        $indexes = collect(Schema::getIndexes('payments'))->keyBy('name');
        $this->assertSame(['payment_method', 'external_reference'], $indexes['payments_method_external_reference_unique']['columns']);
        $this->assertTrue($indexes['payments_method_external_reference_unique']['unique']);
        $this->assertSame(['invoice_id', 'idempotency_key'], $indexes['payments_invoice_idempotency_unique']['columns']);
        $this->assertTrue($indexes['payments_invoice_idempotency_unique']['unique']);
        DB::table('payments')->insert(['invoice_id' => $invoice->id, 'amount' => 1000, 'payment_method' => 'CASH', 'external_reference' => 'SYNTHETIC-PAYOS-0', 'paid_at' => '2026-11-08']);
        $this->assertSame(2001, DB::table('payments')->count());
        $this->expectException(QueryException::class);
        DB::table('payments')->insert(['invoice_id' => $invoice->id, 'amount' => 1000, 'payment_method' => 'PAYOS', 'external_reference' => 'SYNTHETIC-PAYOS-0', 'paid_at' => '2026-11-08']);
    }

    #[TestWith(['idempotency_key'])]
    #[TestWith(['idempotency_fingerprint'])]
    #[TestWith(['payments_invoice_idempotency_unique'])]
    public function test_interrupted_ddl_can_resume_without_changing_historical_rows(string $stage): void
    {
        [, , $before] = $this->historicalDatabase();
        $interrupted = false;
        DB::listen(function ($query) use ($stage, &$interrupted): void {
            if (! $interrupted && str_starts_with($query->sql, 'alter table `payments`') && str_contains($query->sql, $stage)) {
                $interrupted = true;
                throw new \RuntimeException('Synthetic interruption after committed DDL');
            }
        });
        try {
            $this->migrateTarget();
            $this->fail('DDL failure injection must interrupt the migration.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic interruption after committed DDL', $exception->getMessage());
        }
        $this->assertTrue($interrupted);
        $this->assertSame(0, DB::table('migrations')->where('migration', self::MIGRATION)->count());

        $this->migrateTarget();

        $this->assertSame(1, DB::table('migrations')->where('migration', self::MIGRATION)->count());
        $this->assertSame($before, DB::table('payments')->select(array_keys($before[0]))->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertTrue(Schema::hasIndex('payments', ['invoice_id', 'idempotency_key'], 'unique'));
    }

    public function test_existing_incompatible_column_is_rejected_without_modifying_receipts(): void
    {
        [, $migration, $before] = $this->historicalDatabase();
        Schema::table('payments', fn (Blueprint $table) => $table->string('idempotency_key', 10)->nullable());
        try {
            $migration->up();
            $this->fail('An incompatible existing column must require operator review.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Unexpected payments.idempotency_key', $exception->getMessage());
        }
        $this->assertFalse(Schema::hasColumn('payments', 'idempotency_fingerprint'));
        $this->assertSame($before, DB::table('payments')->select(array_keys($before[0]))->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
    }

    public function test_rollback_refuses_to_destroy_replay_keys_after_they_are_used(): void
    {
        [, $migration] = $this->historicalDatabase();
        $this->migrateTarget();
        $id = DB::table('payments')->value('id');
        DB::table('payments')->where('id', $id)->update([
            'idempotency_key' => 'ad8213f9-5c68-4835-bfc2-6ef2bd44b024',
            'idempotency_fingerprint' => str_repeat('a', 64),
        ]);
        try {
            $migration->down();
            $this->fail('Rollback must not silently discard used replay keys.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Payment replay metadata exists', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasIndex('payments', 'payments_invoice_idempotency_unique'));
        $this->assertDatabaseHas('payments', ['id' => $id, 'idempotency_key' => 'ad8213f9-5c68-4835-bfc2-6ef2bd44b024']);
    }
}
