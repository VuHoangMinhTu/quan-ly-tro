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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->enum('payment_method', ['CASH', 'BANK_TRANSFER', 'CARD', 'OTHER']);
            $table->dateTime('paid_at');
            $table->string('reference_code', 255)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->index('invoice_id');
            $table->index('paid_at');
            $table->index('payment_method');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
