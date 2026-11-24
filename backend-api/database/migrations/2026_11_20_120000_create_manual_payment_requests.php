<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_payment_requests', function (Blueprint $table): void {
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key');
            $table->char('payload_hash', 64);
            $table->primary(['invoice_id', 'request_key']);
        });
    }

    public function down(): void
    {
        throw new LogicException('Manual payment request history must be preserved. Roll back application code only.');
    }
};
