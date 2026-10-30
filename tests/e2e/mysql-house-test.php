<?php

use App\Models\Landlord;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

// Dedicated disposable LOCAL instance only. Never accepts connection arguments.
$root = dirname(__DIR__, 2).'/backend-api';
require $root.'/vendor/autoload.php';
$pdo = new PDO('mysql:host=127.0.0.1;port=13317', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ((int) $pdo->query('SELECT @@port')->fetchColumn() !== 13317) {
    throw new RuntimeException('Unexpected MySQL instance');
}
$pdo->exec('CREATE DATABASE IF NOT EXISTS beehouse_concurrency_test');
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '13317', 'DB_DATABASE' => 'beehouse_concurrency_test', 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'mysql', 'database.connections.mysql.host' => '127.0.0.1', 'database.connections.mysql.port' => 13317, 'database.connections.mysql.database' => 'beehouse_concurrency_test', 'database.connections.mysql.username' => 'root', 'database.connections.mysql.password' => '', 'database.connections.mysql.url' => null]);
DB::purge('mysql');
if (DB::selectOne('SELECT DATABASE() AS db')->db !== 'beehouse_concurrency_test') {
    throw new RuntimeException('Wrong database');
}
if (PHP_SAPI === 'cli-server') {
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $request = Request::capture();
    $response = $kernel->handle($request);
    $response->send();
    $kernel->terminate($request, $response);

    return;
}
if (($argv[1] ?? '') === 'worker') {
    $job = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    Event::listen('eloquent.creating: '.Room::class, fn () => usleep(20000));
    $start = microtime(true);
    $request = Request::create('/api/boarding-houses/with-rooms', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$job['token']], json_encode($job['payload']));
    $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
    echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true), 'start' => $start, 'end' => microtime(true)]);
    exit;
}
Artisan::call('migrate:fresh', ['--force' => true]);
$user = User::factory()->create();
$owner = Landlord::create(['user_id' => $user->id, 'full_name' => 'Synthetic owner', 'phone' => '0900000000']);
$payload = ['idempotency_key' => (string) Str::uuid(), 'boarding_house' => ['name' => 'Synthetic house', 'address' => 'Synthetic address'], 'floors' => [['client_key' => 'a', 'name' => 'Floor A', 'sort_order' => 1], ['client_key' => 'b', 'name' => 'Floor B', 'sort_order' => 2]], 'rooms' => array_map(fn ($n) => ['room_code' => (string) $n, 'floor_client_key' => $n <= 25 ? 'a' : 'b'], range(1, 50))];
$token = $user->createToken('isolated-test')->plainTextToken;
// Only fake credentials/data are written here; no production data is involved.
$jobPath = dirname(__DIR__, 2).'/tmp/production-audit/mysql-fake-job.json';
file_put_contents($jobPath, json_encode(['token' => $token, 'payload' => $payload]));
$workers = [];
for ($i = 0; $i < 2; $i++) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', $jobPath], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    fclose($pipes[0]);
    $workers[] = [$process, $pipes];
}
$results = [];
foreach ($workers as [$process,$pipes]) {
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException($error);
    }
    $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}
$db = DB::class;
$counts = ['houses' => $db::table('boarding_houses')->count(), 'rooms' => $db::table('rooms')->count(), 'floors' => $db::table('floors')->count(), 'receipts' => $db::table('house_creation_requests')->count()];
$ok = $results[0]['status'] === 201 && $results[1]['status'] === 201 && $results[0]['body']['data']['id'] === $results[1]['body']['data']['id'] && $counts === ['houses' => 1, 'rooms' => 50, 'floors' => 2, 'receipts' => 1];
$overlap = max(array_column($results, 'start')) < min(array_column($results, 'end'));
echo json_encode(['mysql' => $pdo->query('SELECT VERSION()')->fetchColumn(), 'database' => 'beehouse_concurrency_test', 'concurrent_statuses' => array_column($results, 'status'), 'overlap' => $overlap, 'counts' => $counts, 'passed' => $ok && $overlap], JSON_PRETTY_PRINT);
if (! $ok || ! $overlap) {
    exit(1);
}

function submitFake(array $payload, string $token, string $jobPath, string $root): array
{
    file_put_contents($jobPath, json_encode(['token' => $token, 'payload' => $payload]));
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', $jobPath], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException($error);
    }

    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}
function verifyCase(string $name, bool $ok): void
{
    echo "\n".$name.': '.($ok ? 'PASS' : 'FAIL');
    if (! $ok) {
        throw new RuntimeException($name);
    }
}
$replay = submitFake($payload, $token, $jobPath, $root);
verifyCase('lost_response_replay', $replay['status'] === 201 && $db::table('boarding_houses')->count() === 1);
$changed = $payload;
$changed['rooms'][0]['room_code'] = 'different';
verifyCase('key_payload_conflict', submitFake($changed, $token, $jobPath, $root)['status'] === 422);
$new = $payload;
$new['idempotency_key'] = (string) Str::uuid();
$new['rooms'] = array_slice($new['rooms'], 0, 20);
verifyCase('new_operation_twenty_rooms', submitFake($new, $token, $jobPath, $root)['status'] === 201 && $db::table('rooms')->count() === 70);
$other = User::factory()->create();
Landlord::create(['user_id' => $other->id, 'full_name' => 'Other synthetic owner', 'phone' => '0900000001']);
$otherToken = $other->createToken('isolated-test')->plainTextToken;
verifyCase('same_key_other_user', submitFake($payload, $otherToken, $jobPath, $root)['status'] === 201 && $db::table('boarding_houses')->count() === 3);
$invalid = $new;
$invalid['idempotency_key'] = (string) Str::uuid();
$invalid['rooms'][0]['room_code'] = '';
verifyCase('validation_no_partial_writes', submitFake($invalid, $token, $jobPath, $root)['status'] === 422 && $db::table('rooms')->count() === 120);

// Inject an inconsistent historical restore only in the verified disposable schema.
$next = (int) $db::selectOne("SELECT AUTO_INCREMENT AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='boarding_houses'")->n;
$legacy = $db::table('rooms')->first();
$tenant = Tenant::create(['landlord_id' => $owner->id, 'full_name' => 'Synthetic history']);
$membership = $db::table('room_tenants')->insertGetId(['room_id' => $legacy->id, 'tenant_id' => $tenant->id, 'is_primary' => true, 'move_in_date' => '2026-01-01', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
$db::statement('SET FOREIGN_KEY_CHECKS=0');
try {
    $db::table('rooms')->where('id', $legacy->id)->update(['boarding_house_id' => $next, 'deleted_at' => now()]);
} finally {
    $db::statement('SET FOREIGN_KEY_CHECKS=1');
}
$orphanPayload = $new;
$orphanPayload['idempotency_key'] = (string) Str::uuid();
verifyCase('soft_deleted_orphan_id_reuse_blocked', submitFake($orphanPayload, $token, $jobPath, $root)['status'] === 422 && ! $db::table('boarding_houses')->where('id', $next)->exists());
verifyCase('mysql_rollback_consumes_allocator', submitFake($orphanPayload, $token, $jobPath, $root)['status'] === 201);
verifyCase('historical_room_not_adopted', $db::table('rooms')->where('id', $legacy->id)->value('boarding_house_id') === $next && ! $db::table('boarding_houses')->where('id', $next)->exists());
verifyCase('membership_history_preserved', $db::table('room_tenants')->where('id', $membership)->value('room_id') === $legacy->id);
$future = $next + 100;
$db::statement('SET FOREIGN_KEY_CHECKS=0');
try {
    $db::table('rooms')->where('id', $legacy->id)->update(['boarding_house_id' => $future]);
} finally {
    $db::statement('SET FOREIGN_KEY_CHECKS=1');
}
$before = $db::table('rooms')->count();
// Only on this dedicated disposable instance. Never run this script against production.
$db::statement('ALTER TABLE boarding_houses AUTO_INCREMENT = '.($future + 1));
$fresh = $new;
$fresh['idempotency_key'] = (string) Str::uuid();
$created = submitFake($fresh, $token, $jobPath, $root);
verifyCase('allocator_above_archived_reference', $created['status'] === 201 && $created['body']['data']['id'] > $future && $db::table('rooms')->count() === $before + 20);
verifyCase('allocator_preserves_history',$db::table('rooms')->where('id',$legacy->id)->value('boarding_house_id') === $future && $db::table('room_tenants')->where('id',$membership)->exists());
