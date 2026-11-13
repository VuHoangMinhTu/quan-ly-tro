<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Invoice;
use App\Models\PayOSPaymentRequest;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ManualPaymentMysqlConcurrencyTest extends TestCase
{
    public function test_manual_and_verified_payos_writers_preserve_balance_under_real_contention(): void
    {
        $this->test_real_authenticated_requests_serialize_money_and_replays('payos');
    }

    public function test_manual_holds_invoice_before_verified_webhook_starts_without_deadlock(): void
    {
        $this->test_real_authenticated_requests_serialize_money_and_replays('payos-manual-first');
    }

    #[TestWith(['same'])]
    #[TestWith(['distinct'])]
    #[TestWith(['overpayment'])]
    #[TestWith(['conflict'])]
    #[TestWith(['delete-retry'])]
    #[TestWith(['delete-new'])]
    public function test_real_authenticated_requests_serialize_money_and_replays(string $mode): void
    {
        $payos = str_starts_with($mode, 'payos');
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Requires isolated MySQL, not SQLite.');
        }
        $this->assertSame('testing', app()->environment());
        $this->assertSame('13368', (string) config('database.connections.mysql.port'));
        $this->assertSame('beehouse_billing_v2_test', DB::selectOne('SELECT DATABASE() AS name')->name);
        $this->assertSame('REPEATABLE-READ', DB::selectOne('SELECT @@transaction_isolation AS isolation_level')->isolation_level);
        Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
        $contract = Contract::factory()->create(['status' => 'ACTIVE']);
        $invoice = $contract->invoices()->create(['room_id' => $contract->room_id,
            'billing_period' => '2026-11-01', 'status' => 'UNPAID', 'total_amount' => 1000000]);
        $owner = $contract->room->boardingHouse->landlord->user;
        $token = $owner->createToken('synthetic-concurrency')->plainTextToken;
        $directory = dirname(base_path()).'/tmp/billing-v2-mysql/payment-concurrency-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $payload = ['amount' => $mode === 'overpayment' ? 700000 : 200000,
            'payment_method' => 'CASH', 'paid_at' => '2026-11-08 10:00:00',
            'idempotency_key' => '33e60e84-ff99-4889-8f13-617853d4aa01'];
        $second = $payload;
        if (in_array($mode, ['distinct', 'overpayment'], true)) {
            $second['idempotency_key'] = '4f8f695a-6da2-4ac1-9363-b55e838be002';
        }
        if ($mode === 'conflict') {
            $second['amount'] = 300000;
        }
        $paymentId = null;
        if (str_starts_with($mode, 'delete-')) {
            $this->actingAs($owner, 'sanctum')->postJson("/api/invoices/{$invoice->id}/payments", $payload)->assertCreated();
            $paymentId = $invoice->payments()->value('id');
            if ($mode === 'delete-new') {
                $second['idempotency_key'] = '4f8f695a-6da2-4ac1-9363-b55e838be002';
                $second['amount'] = 300000;
            }
        }
        $workers = [];
        $orderCode = 900000123;
        if ($payos) {
            PayOSPaymentRequest::create(['invoice_id' => $invoice->id, 'order_code' => $orderCode, 'amount' => 1000000, 'status' => 'PENDING']);
        }
        DB::beginTransaction();
        Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
        try {
            foreach ([$payload, $second] as $index => $data) {
                $number = $index + 1;
                file_put_contents($directory.'/job-'.$number.'.json', json_encode(['invoice_id' => $invoice->id, 'token' => $token, 'payload' => $data,
                    'method' => $payos && $number === 2 ? 'PAYOS' : ($paymentId && $number === 1 ? 'DELETE' : 'POST'),
                    'payment_id' => $paymentId, 'order_code' => $orderCode, 'landlord_id' => $owner->landlord->id, 'mode' => $mode], JSON_THROW_ON_ERROR));
                $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/ManualPaymentConcurrentWorker.php'), $directory, (string) $number], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $workers[] = [$process, $pipes];
                if ($mode === 'payos-manual-first' && $number === 1) {
                    $deadline = microtime(true) + 20;
                    while (! is_file($directory.'/attempting-1')) {
                        if (microtime(true) > $deadline) {
                            $this->fail('Manual writer did not attempt the invoice lock.');
                        }
                        clearstatcache();
                        usleep(10000);
                    }
                    DB::commit();
                    while (! is_file($directory.'/invoice-held-1')) {
                        if (microtime(true) > $deadline) {
                            $this->fail('Manual writer did not acquire the invoice lock.');
                        }
                        clearstatcache();
                        usleep(10000);
                    }
                }
            }
            $deadline = microtime(true) + 20;
            while (! is_file($directory.'/attempting-1') || ! is_file($directory.'/attempting-2')) {
                if (microtime(true) >= $deadline) {
                    $this->fail('Both API workers must reach the invoice lock.');
                }
                clearstatcache();
                usleep(10000);
            }
            if ($mode !== 'payos-manual-first') {
                foreach ($workers as [$process]) {
                    $this->assertTrue(proc_get_status($process)['running']);
                }
            }
            if (DB::transactionLevel() > 0) {
                DB::commit();
            }
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                $this->assertSame('', $stderr);
                $results[] = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertNotSame($results[0]['connection_id'], $results[1]['connection_id']);
            if ($payos) {
                $this->assertSame(200, $results[1]['status'], json_encode($results));
                $this->assertContains($results[0]['status'], [201, 422]);
                if ($mode === 'payos-manual-first') {
                    $this->assertSame(201, $results[0]['status']);
                }
                $this->assertSame(1, $invoice->payments()->count());
                $expected = $results[0]['status'] === 201 ? '200000.00' : '1000000.00';
                $this->assertSame($expected, $invoice->fresh()->paid_amount);
                $this->assertSame($expected, (string) $invoice->payments()->sum('amount'));
                $this->assertSame($results[0]['status'] === 201 ? 'PARTIALLY_PAID' : 'PAID', $invoice->fresh()->status);
                $this->assertSame($results[0]['status'] === 201 ? 1 : 0, DB::table('manual_payment_requests')->where('invoice_id', $invoice->id)->count());
                if ($results[0]['status'] === 422) {
                    $this->assertDatabaseHas('payments', ['invoice_id' => $invoice->id, 'payment_method' => 'PAYOS', 'payment_source' => 'PAYOS_WEBHOOK', 'idempotency_key' => null]);
                }

                return;
            }
            if (str_starts_with($mode, 'delete-')) {
                $this->assertSame(200, $results[0]['status']);
                $this->assertContains($results[1]['status'], $mode === 'delete-new' ? [201] : [201, 409]);
                $this->assertSame($mode === 'delete-new' ? 1 : 0, $invoice->payments()->count());
                $this->assertSame($mode === 'delete-new' ? '300000.00' : '0.00', $invoice->fresh()->paid_amount);
                $this->assertSame($mode === 'delete-new' ? 'PARTIALLY_PAID' : 'UNPAID', $invoice->fresh()->status);
                $this->assertDatabaseHas('manual_payment_requests', ['invoice_id' => $invoice->id, 'idempotency_key' => $payload['idempotency_key'], 'state' => 'DELETED']);

                return;
            }
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame(in_array($mode, ['same', 'distinct'], true) ? [201, 201] : [201, 422], $statuses);
            $this->assertSame($mode === 'distinct' ? 2 : 1, $invoice->payments()->count());
            $this->assertSame($mode === 'distinct' ? 2 : 1, DB::table('manual_payment_requests')->where('invoice_id', $invoice->id)->count());
            $expected = $mode === 'distinct' ? '400000.00' : ($mode === 'overpayment' ? '700000.00' : '200000.00');
            if ($mode === 'conflict' && $results[1]['status'] === 201) {
                $expected = '300000.00';
            }
            $this->assertSame($expected, $invoice->fresh()->paid_amount);
            $this->assertSame('PARTIALLY_PAID', $invoice->fresh()->status);
            $this->assertSame($expected, (string) $invoice->payments()->sum('amount'));
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
            foreach ([1, 2] as $number) {
                @unlink($directory.'/job-'.$number.'.json');
            }
        }
    }
}
