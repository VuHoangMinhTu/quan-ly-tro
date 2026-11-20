<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Invoice;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ManualPaymentMysqlConcurrencyTest extends TestCase
{
    private const PAYMENT = [
        'amount' => 200000, 'payment_method' => 'CASH', 'paid_at' => '2026-10-10 08:00:00',
        'request_key' => 'e98ce958-a01d-4d67-9abd-7b1622167a01',
    ];

    public function test_concurrent_retries_create_only_one_payment(): void
    {
        [$invoice, $token] = $this->fixture();
        DB::beginTransaction();
        Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
        $workers = [];
        try {
            $url = "/api/invoices/{$invoice->id}/payments";
            $workers[] = $this->worker($token, 'POST', $url, self::PAYMENT);
            $workers[] = $this->worker($token, 'POST', $url, self::PAYMENT);
            $this->assertBlocked($workers);
            DB::commit();
            $this->assertEqualsCanonicalizing([200, 201], [
                $this->finish($workers[0])['status'], $this->finish($workers[1])['status'],
            ]);
            $this->assertDatabaseCount('payments', 1);
            $this->assertDatabaseCount('manual_payment_requests', 1);
            $this->assertSame('200000.00', $invoice->fresh()->paid_amount);
            $this->assertDatabaseCount('invoices', 1);
            $this->assertDatabaseCount('payos_payment_requests', 0);
        } finally {
            $this->cleanup($workers);
        }
    }

    public function test_invoice_edit_waits_for_payment_and_rechecks_after_lock(): void
    {
        [$invoice, $token, $contract] = $this->fixture();
        $paymentToken = $contract->room->boardingHouse->landlord->user
            ->createToken('payment-concurrency-test')->plainTextToken;
        DB::beginTransaction();
        $workers = [];
        try {
            Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->withToken($paymentToken)->postJson("/api/invoices/{$invoice->id}/payments", self::PAYMENT)->assertCreated();
            $workers[] = $this->worker($token, 'PUT', "/api/invoices/{$invoice->id}", [
                'contract_id' => $contract->id, 'billing_period' => '2026-10-01',
                'status' => 'CANCELLED', 'discount_amount' => 900000,
            ]);
            $this->assertBlocked($workers);
            DB::commit();
            $result = $this->finish($workers[0]);
            $this->assertSame(422, $result['status'], json_encode($result));
            $this->assertSame('PARTIALLY_PAID', $invoice->fresh()->status);
            $this->assertSame('1000000.00', $invoice->fresh()->total_amount);
            $this->assertSame('200000.00', $invoice->fresh()->paid_amount);
            $this->assertDatabaseCount('payments', 1);
            $this->assertDatabaseCount('invoices', 1);
            $this->assertDatabaseCount('payos_payment_requests', 0);
        } finally {
            $this->cleanup($workers);
        }
    }

    private function fixture(): array
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Requires isolated MySQL.');
        }
        $this->assertSame('127.0.0.1', config('database.connections.mysql.host'));
        $this->assertSame('13368', (string) config('database.connections.mysql.port'));
        $this->assertSame('billing_test', config('database.connections.mysql.username'));
        $this->assertSame('beehouse_billing_v2_test', DB::selectOne('SELECT DATABASE() AS name')->name);
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]));
        $contract = Contract::factory()->create(['status' => 'ACTIVE']);
        $owner = $contract->room->boardingHouse->landlord->user;
        $invoice = $contract->invoices()->create([
            'room_id' => $contract->room_id, 'billing_period' => '2026-10-01', 'status' => 'UNPAID',
            'subtotal' => 1000000, 'total_amount' => 1000000,
        ]);

        return [$invoice, $owner->createToken('concurrency-test')->plainTextToken, $contract];
    }

    private function worker(string $token, string $method, string $path, array $payload): array
    {
        $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/ManualPaymentWorker.php')], [
            ['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w'],
        ], $pipes, base_path());
        $this->assertIsResource($process);
        fwrite($pipes[0], json_encode(compact('token', 'method', 'path', 'payload'), JSON_THROW_ON_ERROR));
        fclose($pipes[0]);

        return [$process, $pipes];
    }

    private function assertBlocked(array $workers): void
    {
        // Observe the requests inside MySQL while the parent still holds the invoice lock.
        $deadline = microtime(true) + 12;
        do {
            foreach ($workers as [$process, $pipes]) {
                $status = proc_get_status($process);
                if (! $status['running']) {
                    $this->fail('Worker completed before the invoice lock was released: '.stream_get_contents($pipes[1]).stream_get_contents($pipes[2]));
                }
            }
            $waiting = DB::selectOne("SELECT COUNT(*) AS waiting FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND COMMAND IN ('Query', 'Execute') AND (LOWER(INFO) LIKE '%invoices%for update%' OR LOWER(INFO) LIKE 'update %invoices% set %')")->waiting;
            if ((int) $waiting >= count($workers)) {
                return;
            }
            usleep(50000);
        } while (microtime(true) < $deadline);

        $this->fail('Workers did not reach the invoice lock while the parent held it.');
    }

    private function finish(array $worker): array
    {
        [$process, $pipes] = $worker;
        $deadline = microtime(true) + 12;
        do {
            $status = proc_get_status($process);
            if (! $status['running']) {
                $this->assertSame(0, $status['exitcode'], stream_get_contents($pipes[2]));

                return json_decode(stream_get_contents($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
            }
            usleep(50000);
        } while (microtime(true) < $deadline);

        $this->fail('Worker did not complete after releasing the invoice lock.');
    }

    private function cleanup(array $workers): void
    {
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
