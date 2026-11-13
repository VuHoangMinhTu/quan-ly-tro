<?php

use App\Services\PayOSService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PayOS\Models\Webhooks\WebhookData;

require __DIR__.'/BillingV2MysqlBootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
Http::preventStrayRequests();
$directory = realpath($argv[1] ?? '');
$prefix = str_replace('\\', '/', dirname(__DIR__, 3).'/tmp/billing-v2-mysql/');
if (! $directory || ! str_starts_with(str_replace('\\', '/', $directory).'/', $prefix)) {
    throw new RuntimeException('Invalid isolated test barrier.');
}
$number = $argv[2];
$job = json_decode(file_get_contents($directory.'/job-'.$number.'.json'), true, flags: JSON_THROW_ON_ERROR);
$connection = DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
DB::beforeExecuting(function (string $sql) use ($directory, $number): void {
    if (str_contains($sql, 'from `invoices`') && str_contains($sql, 'for update')) {
        file_put_contents($directory.'/attempting-'.$number, 'attempting');
    }
});
if (($job['mode'] ?? '') === 'payos-manual-first' && $number === '1') {
    DB::listen(function ($query) use ($directory): void {
        if (str_contains($query->sql, 'from `invoices`') && str_contains($query->sql, 'for update')) {
            file_put_contents($directory.'/invoice-held-1', 'locked');
            $deadline = microtime(true) + 20;
            while (! is_file($directory.'/attempting-2')) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Second writer did not reach the locked invoice.');
                }
                clearstatcache();
                usleep(10000);
            }
        }
    });
}
$method = $job['method'] ?? 'POST';
if ($method === 'PAYOS') {
    try {
        $data = new WebhookData($job['order_code'], 1000000, 'Synthetic payment', '123', 'SYNTHETIC-PAYOS-RACE',
            '2026-11-08T10:00:00+07:00', 'VND', 'synthetic-link', '00', 'success');
        app(PayOSService::class)->processVerifiedWebhook($data, $job['landlord_id'], null);
        echo json_encode(['connection_id' => $connection, 'status' => 200], JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        echo json_encode(['connection_id' => $connection, 'status' => 500, 'db_code' => $exception->errorInfo[1] ?? null], JSON_THROW_ON_ERROR);
    }
    exit;
}
$url = $method === 'DELETE' ? '/api/payments/'.$job['payment_id'] : '/api/invoices/'.$job['invoice_id'].'/payments';
$request = Request::create($url, $method, [], [], [], [
    'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
    'HTTP_AUTHORIZATION' => 'Bearer '.$job['token'],
], json_encode($job['payload'], JSON_THROW_ON_ERROR));
$response = $kernel->handle($request);
echo json_encode(['connection_id' => $connection, 'status' => $response->getStatusCode()], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
