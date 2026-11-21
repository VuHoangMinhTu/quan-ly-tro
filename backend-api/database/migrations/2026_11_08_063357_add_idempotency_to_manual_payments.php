<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $columns = collect(Schema::getColumns('payments'))->keyBy('name');
        foreach (['idempotency_key' => 36, 'idempotency_fingerprint' => 64] as $name => $length) {
            if (isset($columns[$name]) && (! $columns[$name]['nullable']
                || $columns[$name]['default'] !== null
                || (DB::getDriverName() === 'mysql' && $columns[$name]['type'] !== 'char('.$length.')'))) {
                throw new RuntimeException('Unexpected payments.'.$name.' definition; review schema before retrying.');
            }
        }
        $index = collect(Schema::getIndexes('payments'))->firstWhere('name', 'payments_invoice_idempotency_unique');
        if ($index && (! $index['unique'] || $index['columns'] !== ['invoice_id', 'idempotency_key'])) {
            throw new RuntimeException('Unexpected payments_invoice_idempotency_unique definition; review schema before retrying.');
        }

        foreach (['idempotency_key' => 36, 'idempotency_fingerprint' => 64] as $name => $length) {
            if (! isset($columns[$name])) {
                Schema::table('payments', function (Blueprint $table) use ($name, $length): void {
                    $table->char($name, $length)->nullable();
                });
            }
        }
        if (! $index) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->unique(['invoice_id', 'idempotency_key'], 'payments_invoice_idempotency_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('manual_payment_requests') && DB::table('manual_payment_requests')->exists()) {
            throw new RuntimeException('Payment replay ledger exists; keep the additive schema.');
        }
        foreach (['idempotency_key', 'idempotency_fingerprint'] as $name) {
            if (Schema::hasColumn('payments', $name) && DB::table('payments')->whereNotNull($name)->exists()) {
                throw new RuntimeException('Payment replay metadata exists. Keep additive schema and roll back application code only.');
            }
        }
        if (Schema::hasIndex('payments', 'payments_invoice_idempotency_unique')) {
            Schema::table('payments', fn (Blueprint $table) => $table->dropUnique('payments_invoice_idempotency_unique'));
        }
        foreach (['idempotency_key', 'idempotency_fingerprint'] as $name) {
            if (Schema::hasColumn('payments', $name)) {
                Schema::table('payments', fn (Blueprint $table) => $table->dropColumn($name));
            }
        }
    }
};
