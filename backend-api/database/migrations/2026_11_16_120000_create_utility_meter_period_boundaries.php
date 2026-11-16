<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utility_meter_period_boundaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('utility_meter_id')->constrained()->restrictOnDelete();
            $table->date('billing_period');
            $table->foreignId('start_checkpoint_id')->constrained('utility_checkpoints', indexName: 'umpb_start_fk')->restrictOnDelete();
            $table->foreignId('end_checkpoint_id')->constrained('utility_checkpoints', indexName: 'umpb_end_fk')->restrictOnDelete();
            $table->unique(['utility_meter_id', 'billing_period'], 'umpb_meter_period_unique');
        });
    }

    public function down(): void
    {
        throw new LogicException('Confirmed meter period links must be preserved; rollback is code-only.');
    }
};
