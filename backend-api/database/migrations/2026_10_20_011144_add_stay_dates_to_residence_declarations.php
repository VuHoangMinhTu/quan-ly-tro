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
        Schema::table('residence_declarations', function (Blueprint $table) {
            $table->date('stay_start_date')->nullable();
            $table->date('stay_end_date')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('residence_declarations', function (Blueprint $table) {
            $table->dropColumn(['stay_start_date', 'stay_end_date']);
        });
    }
};
