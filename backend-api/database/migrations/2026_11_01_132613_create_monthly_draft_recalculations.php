<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('monthly_draft_recalculations', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->date('billing_period');
            $table->string('status', 20)->default('PREVIEWED');
            $table->char('before_hash', 64);
            $table->char('candidate_hash', 64);
            $table->json('before_snapshot');
            $table->json('candidate_snapshot');
            $table->json('confirmations');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->index(['room_id', 'billing_period']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Monthly draft recalculation history is audit evidence and must not be dropped by rollback.');
    }
};
