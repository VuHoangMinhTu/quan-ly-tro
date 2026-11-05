<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('utility_checkpoint_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utility_meter_id')->constrained('utility_meters')->restrictOnDelete();
            $table->foreignId('old_start_checkpoint_id')->constrained('utility_checkpoints')->restrictOnDelete();
            $table->foreignId('old_end_checkpoint_id')->constrained('utility_checkpoints')->restrictOnDelete();
            $table->foreignId('new_start_checkpoint_id')->constrained('utility_checkpoints')->restrictOnDelete();
            $table->foreignId('new_end_checkpoint_id')->constrained('utility_checkpoints')->restrictOnDelete();
            $table->string('request_key', 36);
            $table->string('request_hash', 64);
            $table->text('reason');
            $table->foreignId('corrected_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['utility_meter_id', 'request_key'], 'utility_correction_request_unique');
        });
    }

    public function down(): void
    {
        throw new LogicException('Không xóa lịch sử đính chính chỉ số điện nước; rollback code-only.');
    }
};
