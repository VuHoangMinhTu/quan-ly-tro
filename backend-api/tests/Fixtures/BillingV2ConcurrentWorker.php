<?php

use App\Models\Room;
use App\Services\MonthlyDraftRecalculation;
use App\Services\MonthlyInvoiceBilling;
use App\Services\UtilityPeriodBilling;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/BillingV2MysqlBootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'beehouse_billing_v2_test'
    || config('database.connections.mysql.host') !== '127.0.0.1' || (string) config('database.connections.mysql.port') !== '13368') {
    throw new RuntimeException('Effective database is not isolated.');
}
$directory = realpath($argv[2] ?? '');
$prefix = str_replace('\\', '/', dirname(__DIR__, 3).'/tmp/billing-v2-mysql/');
if (! $directory || ! str_starts_with(str_replace('\\', '/', $directory).'/', $prefix)) {
    throw new RuntimeException('Invalid barrier path.');
}
$worker = $argv[3];
file_put_contents($directory.'/ready-'.$worker, 'ready');
$deadline = microtime(true) + 15;
while (! is_file($directory.'/go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Timed out waiting for concurrency barrier.');
    }
    usleep(10000);
}
file_put_contents($directory.'/attempting-'.$worker, 'attempting');
$room = Room::findOrFail((int) $argv[1]);
if (($argv[4] ?? '') === 'recalculate') {
    $confirmed = $app->make(MonthlyDraftRecalculation::class)->confirm($room, $argv[5], 'Synthetic concurrent correction', $room->boardingHouse->landlord->user_id);
    $result = ['invoices' => $room->invoices()->get()->all()];
} elseif (($argv[4] ?? '') === 'monthly') {
    $result = $app->make(MonthlyInvoiceBilling::class)->generate($room->boardingHouse, '2026-10-01', '2026-10-01 00:00:00', '2026-11-01 00:00:00', [], $room->boardingHouse->landlord->user_id)[0];
    if (! in_array($result['status'], ['CREATED', 'EXISTING'], true)) {
        throw new RuntimeException(json_encode($result, JSON_THROW_ON_ERROR));
    }
} else {
    $result = $app->make(UtilityPeriodBilling::class)->generate($room, '2026-10-01', '2026-10-01 00:00:00', '2026-11-01 00:00:00', $room->boardingHouse->landlord->user_id);
}
echo json_encode(['connection_id' => DB::selectOne('SELECT CONNECTION_ID() AS id')->id,
    'invoice_ids' => array_map(fn ($invoice) => $invoice->id, $result['invoices']), 'replayed' => $confirmed['replayed'] ?? null], JSON_THROW_ON_ERROR);
