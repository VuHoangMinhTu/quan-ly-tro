<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payos_payment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('order_code')->nullable()->unique();
            $table->string('payment_link_id')->nullable()->unique();
            $table->text('checkout_url')->nullable();
            $table->longText('qr_code')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('status', 40)->default('PENDING');
            $table->dateTime('expired_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payos_payment_requests');
    }
};
