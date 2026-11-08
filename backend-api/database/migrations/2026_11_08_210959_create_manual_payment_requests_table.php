<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('manual_payment_requests')) {
            Schema::create('manual_payment_requests', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('landlord_id');
                $table->unsignedBigInteger('invoice_id');
                $table->unsignedBigInteger('payment_id')->nullable()->index();
                $table->char('idempotency_key', 36);
                $table->char('idempotency_fingerprint', 64);
                $table->enum('state', ['APPLIED', 'DELETED']);
                $table->timestamp('deleted_at')->nullable();
                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->timestamps();
                $table->unique(['landlord_id', 'invoice_id', 'idempotency_key'], 'manual_payment_request_scope_unique');
            });
        }
        $columns = Schema::getColumnListing('manual_payment_requests');
        foreach (['id', 'landlord_id', 'invoice_id', 'payment_id', 'idempotency_key', 'idempotency_fingerprint', 'state', 'deleted_at', 'deleted_by', 'created_at', 'updated_at'] as $column) {
            if (! in_array($column, $columns, true)) {
                throw new RuntimeException('Unexpected manual payment ledger schema; review before retrying.');
            }
        }
        $definitions = collect(Schema::getColumns('manual_payment_requests'))->keyBy('name');
        foreach (['idempotency_key' => 36, 'idempotency_fingerprint' => 64] as $column => $length) {
            if ($definitions[$column]['nullable'] || $definitions[$column]['default'] !== null
                || (DB::getDriverName() === 'mysql' && $definitions[$column]['type'] !== 'char('.$length.')')) {
                throw new RuntimeException('Unexpected manual payment ledger column definition.');
            }
        }
        $index = collect(Schema::getIndexes('manual_payment_requests'))->firstWhere('name', 'manual_payment_request_scope_unique');
        if (($index && (! $index['unique'] || $index['columns'] !== ['landlord_id', 'invoice_id', 'idempotency_key']))
            || Schema::getForeignKeys('manual_payment_requests') !== []) {
            throw new RuntimeException('Manual payment ledger must have the expected unique scope and no cascading foreign keys.');
        }
        if (! $index) {
            Schema::table('manual_payment_requests', fn (Blueprint $table) => $table->unique(['landlord_id', 'invoice_id', 'idempotency_key'], 'manual_payment_request_scope_unique'));
        }
        if (! Schema::hasIndex('manual_payment_requests', ['payment_id'])) {
            Schema::table('manual_payment_requests', fn (Blueprint $table) => $table->index('payment_id'));
        }

        if (DB::table('payments')->whereNull('idempotency_key')->whereNotNull('idempotency_fingerprint')->exists()) {
            throw new RuntimeException('Payment fingerprint exists without its key; reconcile before migrating.');
        }
        DB::table('payments as p')->leftJoin('invoices as i', 'i.id', '=', 'p.invoice_id')
            ->leftJoin('rooms as r', 'r.id', '=', 'i.room_id')
            ->leftJoin('boarding_houses as h', 'h.id', '=', 'r.boarding_house_id')
            ->whereNotNull('p.idempotency_key')
            ->select(['p.id as payment_id', 'p.invoice_id', 'p.idempotency_key', 'p.idempotency_fingerprint', 'p.payment_source', 'p.created_at', 'h.landlord_id'])
            ->chunkById(250, function ($rows): void {
                foreach ($rows as $row) {
                    if (! $row->landlord_id || $row->payment_source === 'PAYOS_WEBHOOK'
                        || ! Str::isUuid($row->idempotency_key)
                        || ! preg_match('/^[a-f0-9]{64}$/D', (string) $row->idempotency_fingerprint)) {
                        throw new RuntimeException('Keyed payment has incomplete ownership or replay evidence; reconcile before migrating.');
                    }
                    $scope = ['landlord_id' => $row->landlord_id, 'invoice_id' => $row->invoice_id, 'idempotency_key' => $row->idempotency_key];
                    DB::table('manual_payment_requests')->insertOrIgnore([
                        ...$scope, 'payment_id' => $row->payment_id, 'idempotency_fingerprint' => $row->idempotency_fingerprint,
                        'state' => 'APPLIED', 'created_at' => $row->created_at ?? now(), 'updated_at' => now(),
                    ]);
                    $record = DB::table('manual_payment_requests')->where($scope)->first();
                    if (! $record || $record->state !== 'APPLIED' || (int) $record->payment_id !== $row->payment_id
                        || ! hash_equals($record->idempotency_fingerprint, $row->idempotency_fingerprint)) {
                        throw new RuntimeException('Existing replay ledger conflicts with a historical payment; reconcile without overwriting evidence.');
                    }
                }
            }, 'p.id', 'payment_id');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('manual_payment_requests') && DB::table('manual_payment_requests')->exists()) {
            throw new RuntimeException('Manual payment replay history exists; keep ledger and roll back application code only.');
        }
        Schema::dropIfExists('manual_payment_requests');
    }
};
