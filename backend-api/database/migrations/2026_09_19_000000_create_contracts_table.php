<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('contract_code', 100)->unique();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('signed_date')->nullable();
            $table->decimal('monthly_rent', 15, 2);
            $table->decimal('deposit_amount', 15, 2)->default(0);
            $table->enum('status', ['DRAFT', 'ACTIVE', 'EXPIRED', 'TERMINATED']);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('room_id');
            $table->index('tenant_id');
            $table->index('status');
            $table->index(['start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
