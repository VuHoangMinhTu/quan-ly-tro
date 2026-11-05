<?php

use Carbon\CarbonImmutable;
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
        Schema::table('utility_billing_periods', function (Blueprint $table): void {
            $table->dateTime('usage_start_at')->nullable();
            $table->dateTime('usage_end_at')->nullable();
            $table->foreignId('start_checkpoint_id')->nullable()->constrained('utility_checkpoints')->restrictOnDelete();
            $table->foreignId('end_checkpoint_id')->nullable()->constrained('utility_checkpoints')->restrictOnDelete();
            $table->foreignId('start_confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('end_confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('start_confirmed_at')->nullable();
            $table->timestamp('end_confirmed_at')->nullable();
            $table->foreignId('calculated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('calculated_at')->nullable();
        });

        foreach (DB::table('utility_billing_periods')->orderBy('id')->cursor() as $period) {
            $start = CarbonImmutable::parse($period->billing_period.' 00:00:00', 'Asia/Ho_Chi_Minh');
            $end = $start->addMonth();
            $startCheckpoint = DB::table('utility_checkpoints')->where('utility_meter_id', $period->utility_meter_id)
                ->where('scope_key', 'P:'.$start->format('Y-m-d H:i:s'))->orderByDesc('revision')->first();
            $endCheckpoint = DB::table('utility_checkpoints')->where('utility_meter_id', $period->utility_meter_id)
                ->where('scope_key', 'P:'.$end->format('Y-m-d H:i:s'))->orderByDesc('revision')->first();
            DB::table('utility_billing_periods')->where('id', $period->id)->update([
                'usage_start_at' => $start->format('Y-m-d H:i:s'),
                'usage_end_at' => $end->format('Y-m-d H:i:s'),
                'start_checkpoint_id' => $startCheckpoint?->id,
                'end_checkpoint_id' => $endCheckpoint?->id,
                'start_confirmed_by' => $startCheckpoint?->confirmed_by,
                'end_confirmed_by' => $endCheckpoint?->confirmed_by,
                'start_confirmed_at' => $startCheckpoint?->created_at,
                'end_confirmed_at' => $endCheckpoint?->created_at,
            ]);
        }

        Schema::table('utility_billing_periods', function (Blueprint $table): void {
            $table->unique(['utility_meter_id', 'usage_start_at', 'usage_end_at'], 'meter_usage_window_unique');
        });
        Schema::table('utility_billing_periods', function (Blueprint $table): void {
            $table->dropUnique('meter_period_unique');
        });

        Schema::table('monthly_draft_recalculations', function (Blueprint $table): void {
            $table->dateTime('usage_start_at')->nullable();
            $table->dateTime('usage_end_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Flexible utility-cycle evidence must be preserved; rollback is code-only.');
    }
};
