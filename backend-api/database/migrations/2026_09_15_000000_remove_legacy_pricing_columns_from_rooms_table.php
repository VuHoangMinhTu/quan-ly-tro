<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn([
                'electricity_price',
                'water_price',
                'water_charge_type',
                'service_fee',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->decimal('electricity_price', 15, 2);
            $table->decimal('water_price', 15, 2);
            $table->string('water_charge_type', 20);
            $table->decimal('service_fee', 15, 2);
        });
    }
};
