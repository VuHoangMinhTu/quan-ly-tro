<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boarding_house_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['ELECTRICITY', 'WATER', 'INTERNET', 'PARKING', 'TRASH', 'CLEANING', 'OTHER']);
            $table->enum('billing_method', ['FIXED', 'PER_UNIT', 'PER_PERSON', 'TIERED']);
            $table->string('unit', 50)->nullable();
            $table->decimal('base_price', 15, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
