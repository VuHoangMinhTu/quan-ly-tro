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
        Schema::create('utility_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utility_meter_id')->constrained()->cascadeOnDelete();
            $table->date('reading_date');
            $table->decimal('reading_value', 15, 2);
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->unique(['utility_meter_id', 'reading_date']);
            $table->index('utility_meter_id');
            $table->index('reading_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('utility_readings');
    }
};
