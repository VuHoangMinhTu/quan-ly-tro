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
        Schema::table('contracts', function (Blueprint $table) {
            $table->unsignedTinyInteger('service_terms_version')->nullable();
        });
        Schema::table('contract_services', function (Blueprint $table) {
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->text('adjustment_reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Also keeps the FK's leading-column index when the old unique is removed.
            $table->index(['contract_id', 'service_id', 'effective_from'], 'contract_services_period_index');
            $table->dropUnique(['contract_id', 'service_id']);
        });
        // The old schema allowed exactly one initial snapshot per service.
        // Infer only its known start, never fabricate legacy price adjustments.
        DB::table('contracts')->whereNotNull('start_date')->orderBy('id')->chunkById(200, function ($contracts): void {
            foreach ($contracts as $contract) {
                $count = DB::table('contract_services')->where('contract_id', $contract->id)->update(['effective_from' => $contract->start_date]);
                $snapshot = json_decode($contract->signing_snapshot ?? 'null', true);
                if ($count > 0 || (is_array($snapshot) && array_key_exists('services', $snapshot))) {
                    DB::table('contracts')->where('id', $contract->id)->update(['service_terms_version' => 1]);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('contract_services')->whereNotNull('adjustment_reason')->exists()
            || DB::table('contract_services')->whereNotNull('effective_to')->exists()
            || DB::table('contracts')->whereNotNull('service_terms_version')->exists()) {
            throw new RuntimeException('Versioned service evidence must be preserved. Use a forward migration.');
        }
        Schema::table('contract_services', function (Blueprint $table) {
            $table->unique(['contract_id', 'service_id']);
            $table->dropIndex('contract_services_period_index');
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropColumn(['effective_from', 'effective_to', 'adjustment_reason']);
        });
        Schema::table('contracts', fn (Blueprint $table) => $table->dropColumn('service_terms_version'));
    }
};
