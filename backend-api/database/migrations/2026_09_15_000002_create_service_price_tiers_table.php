<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->decimal('from_quantity', 15, 2);
            $table->decimal('to_quantity', 15, 2)->nullable();
            $table->decimal('unit_price', 15, 2);
            $table->unsignedInteger('tier_order');
            $table->timestamps();

            $table->unique(['service_id', 'tier_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_price_tiers');
    }
};
