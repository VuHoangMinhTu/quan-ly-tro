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
        Schema::create('monthly_invoice_compositions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->date('billing_period');
            $table->json('snapshot');
            $table->json('confirmation');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unique(['contract_id', 'billing_period'], 'monthly_contract_period_unique');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Monthly invoice composition evidence must be preserved; rollback is code-only.');
    }
};
