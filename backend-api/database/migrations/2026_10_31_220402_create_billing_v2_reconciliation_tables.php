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
        Schema::create('contract_terminations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->unique()->constrained()->restrictOnDelete();
            $table->dateTime('actual_ended_at');
            $table->string('reason', 30);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('utility_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utility_meter_id')->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('scope_key', 80);
            $table->unsignedInteger('revision');
            $table->dateTime('observed_at');
            $table->decimal('reading_value', 15, 2);
            $table->foreignId('confirmed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['utility_meter_id', 'scope_key', 'revision'], 'checkpoint_revision_unique');
        });
        Schema::create('utility_billing_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utility_meter_id')->constrained()->restrictOnDelete();
            $table->date('billing_period');
            $table->string('timezone', 50);
            $table->decimal('quantity', 15, 2);
            $table->decimal('tariff_total', 15, 0);
            $table->json('snapshot');
            $table->timestamps();
            $table->unique(['utility_meter_id', 'billing_period'], 'meter_period_unique');
        });
        Schema::create('utility_charge_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utility_billing_period_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('segment_order');
            $table->foreignId('contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('from_checkpoint_id')->constrained('utility_checkpoints')->restrictOnDelete();
            $table->foreignId('to_checkpoint_id')->constrained('utility_checkpoints')->restrictOnDelete();
            $table->string('responsible_party', 15);
            $table->string('reason', 20);
            $table->decimal('quantity', 15, 2);
            $table->decimal('tariff_amount', 15, 0);
            $table->decimal('supplier_cost', 15, 0)->nullable();
            $table->foreignId('invoice_item_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->unique(['utility_billing_period_id', 'segment_order'], 'period_segment_unique');
        });
        Schema::create('contract_deposit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->string('event', 15);
            $table->decimal('amount', 15, 0);
            $table->string('request_key', 80);
            $table->dateTime('occurred_at');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['contract_id', 'request_key'], 'deposit_request_unique');
        });
        Schema::create('invoice_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->date('billing_period');
            $table->string('kind', 20)->default('UTILITY_V2');
            $table->foreignId('invoice_id')->unique()->constrained()->restrictOnDelete();
            $table->unique(['room_id', 'contract_id', 'billing_period', 'kind'], 'invoice_obligation_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Billing V2 reconciliation and utility evidence must be preserved; rollback is code-only.');
    }
};
