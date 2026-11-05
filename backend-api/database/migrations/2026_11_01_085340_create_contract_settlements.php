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
        Schema::create('contract_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('contract_termination_id')->unique()->constrained()->restrictOnDelete();
            $table->date('billing_period');
            $table->string('status', 40);
            $table->string('collection_policy', 40);
            $table->string('confirmation_state', 40);
            $table->string('deposit_status', 40);
            $table->string('invoice_review_status', 40);
            $table->json('policy_snapshot');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('confirmation_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('contract_settlement_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_settlement_id')->constrained()->restrictOnDelete();
            $table->foreignId('utility_meter_id')->constrained()->restrictOnDelete();
            $table->foreignId('check_in_checkpoint_id')->constrained('utility_checkpoints')->restrictOnDelete();
            $table->foreignId('check_out_checkpoint_id')->constrained('utility_checkpoints')->restrictOnDelete();
            $table->foreignId('period_opening_checkpoint_id')->nullable()->constrained('utility_checkpoints')->restrictOnDelete();
            $table->decimal('quantity_since_check_in', 15, 2);
            $table->decimal('checkout_period_quantity', 15, 2)->nullable();
            $table->decimal('final_period_quantity', 15, 2)->nullable();
            $table->decimal('tariff_amount', 15, 0)->nullable();
            $table->string('responsible_party', 15);
            $table->string('status', 40)->default('PENDING_FINAL_SETTLEMENT');
            $table->timestamps();
            $table->unique(['contract_settlement_id', 'utility_meter_id'], 'settlement_meter_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Contract settlement and usage evidence must be preserved; rollback is code-only.');
    }
};
