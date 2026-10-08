<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boarding_house_id')->constrained()->cascadeOnDelete();
            $table->string('room_code', 50);
            $table->string('room_name')->nullable();
            $table->decimal('area', 10, 2)->nullable();
            $table->decimal('monthly_rent', 15, 2);
            $table->decimal('electricity_price', 15, 2);
            $table->decimal('water_price', 15, 2);
            $table->string('water_charge_type', 20);
            $table->decimal('service_fee', 15, 2);
            $table->unsignedInteger('max_tenants')->nullable();
            $table->string('status', 20);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['boarding_house_id', 'room_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
