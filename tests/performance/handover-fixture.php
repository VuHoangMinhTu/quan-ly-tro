<?php

use App\Models\Contract;
use App\Models\ContractHandoverReport;
use App\Services\ContractHandoverService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/** Disposable loopback audit server. Never boots against the application database. */
$database = getenv('BEEHOUSE_AUDIT_DB') ?: '';
$directory = realpath(dirname($database));
if (! $directory || ! str_starts_with($directory, sys_get_temp_dir().DIRECTORY_SEPARATOR.'beehouse-handover-') || basename($database) !== 'audit.sqlite') {
    throw new RuntimeException('A disposable audit database is required.');
}
$backend = dirname(__DIR__, 2).'/backend-api';
if (is_file($backend.'/bootstrap/cache/config.php')) {
    throw new RuntimeException('Refusing cached application configuration.');
}
foreach (['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'null', 'BCRYPT_ROUNDS' => '4'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $database) {
    throw new RuntimeException('Audit database guard failed.');
}

if (PHP_SAPI === 'cli') {
    if (! is_file($database)) {
        touch($database);
    }
    Artisan::call('migrate', ['--force' => true]);
    $contract = Contract::factory()->create(['contract_code' => 'AUDIT-HANDOVER', 'status' => 'DRAFT']);
    $owner = $contract->room->boardingHouse->landlord->user;
    $token = $owner->createToken('disposable-handover-audit')->plainTextToken;
    $contract->handoverReports()->create(['type' => 'CHECK_IN', 'status' => 'DRAFT', 'items' => []]);
    echo json_encode(['id' => $contract->id, 'token' => $token], JSON_THROW_ON_ERROR);
    exit;
}

if (! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) || ! hash_equals(getenv('BEEHOUSE_AUDIT_KEY'), $_SERVER['HTTP_X_AUDIT_KEY'] ?? '')) {
    http_response_code(404);
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/__audit/reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
    $count = (int) ($data['count'] ?? 20);
    if (! in_array($count, [13, 20, 50], true)) {
        http_response_code(400);
        exit;
    }
    $items = array_map(fn ($name) => ['name' => $name, 'quantity' => 1, 'condition' => 'GOOD', 'note' => 'Synthetic fixture'], ContractHandoverService::ROOM_RENTAL_ITEMS);
    for ($index = 13; $index < $count; $index++) {
        $items[] = ['name' => 'Thiết bị thử nghiệm '.($index + 1), 'quantity' => 1, 'condition' => 'GOOD', 'note' => 'Synthetic fixture'];
    }
    ContractHandoverReport::query()->firstOrFail()->update(['items' => $items, 'status' => ($data['confirmed'] ?? false) ? 'CONFIRMED' : 'DRAFT', 'confirmed_at' => null]);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}
if (! preg_match('~^/api/contracts/\d+(?:/handover-check-in(?:/confirm)?|/services)?$~D', $path) || (! in_array($_SERVER['REQUEST_METHOD'], ['GET', 'PUT'], true) && ! str_ends_with($path, '/confirm'))) {
    http_response_code(404);
    exit;
}
$counts = ['queries' => 0, 'selects' => 0, 'updates' => 0, 'inserts' => 0, 'transactions' => 0];
DB::listen(function ($query) use (&$counts): void {
    $counts['queries']++;
    foreach (['selects' => 'select', 'updates' => 'update', 'inserts' => 'insert'] as $key => $prefix) {
        if (str_starts_with(strtolower(ltrim($query->sql)), $prefix)) {
            $counts[$key]++;
        }
    }
});
Event::listen(TransactionBeginning::class, function () use (&$counts): void {
    $counts['transactions']++;
});
$started = hrtime(true);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Request::capture();
$response = $kernel->handle($request);
$response->headers->set('X-Audit-Kernel-Ms', (string) round((hrtime(true) - $started) / 1e6, 3));
foreach ($counts as $name => $value) {
    $response->headers->set('X-Audit-'.$name, (string) $value);
}
$response->send();
$kernel->terminate($request, $response);
