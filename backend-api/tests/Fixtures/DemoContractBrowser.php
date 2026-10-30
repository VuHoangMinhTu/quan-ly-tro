<?php

// CLI-only fixture helper for the real-Laravel browser regression. Never a web route.
use App\Models\Contract;
use App\Models\Landlord;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite') {
    throw new RuntimeException('Isolated SQLite testing environment required.');
}
$root = dirname(__DIR__, 3);
$db = realpath((string) getenv('DB_DATABASE'));
if (! $db || ! str_starts_with(str_replace('\\', '/', $db), str_replace('\\', '/', $root).'/tmp/demo-contract-')) {
    throw new RuntimeException('Database must be an existing demo-contract fixture under tmp.');
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || realpath(config('database.connections.sqlite.database')) !== $db) {
    throw new RuntimeException('Effective database differs from isolated fixture.');
}
$command = $argv[1] ?? '';
if ($command === 'verify') {
    $user = User::where('email', 'demo-browser@example.invalid')->firstOrFail();
    $user->forceFill(['email_verified_at' => now()])->save();
} elseif ($command === 'full') {
    $user = User::create(['name' => 'Full Browser Test', 'email' => 'full-browser@example.invalid', 'password' => Hash::make('BrowserTest123!')]);
    $user->forceFill(['email_verified_at' => now(), 'contract_access' => 'FULL'])->save();
    Landlord::create(['user_id' => $user->id, 'full_name' => $user->name, 'phone' => '0000000000']);
} elseif ($command === 'assert') {
    $contracts = Contract::where('is_demo', true)->get();
    if ($contracts->count() !== 2 || $contracts->where('status', 'DRAFT')->count() !== 2) {
        throw new RuntimeException('Expected two persisted demo drafts.');
    }
    foreach ($contracts as $contract) {
        $snapshot = $contract->demo_document_snapshot;
        if ($snapshot['template_id'] !== $contract->contract_template_id || $snapshot['document_hash'] !== hash('sha256', $snapshot['rendered_html']) || str_contains($snapshot['rendered_html'], 'ĐIỀU 3:')) {
            throw new RuntimeException('Invalid demo snapshot.');
        }
    }
    foreach (['room_tenants', 'contract_documents', 'invoices', 'payments'] as $table) {
        if (DB::table($table)->count() !== 0) {
            throw new RuntimeException('Unexpected operational records: '.$table);
        }
    }
    echo json_encode(['demo_contracts' => $contracts->map->only(['id', 'contract_template_id', 'status', 'is_demo'])->all(), 'operational_records' => 0]);
} else {
    throw new RuntimeException('Unknown fixture command.');
}
