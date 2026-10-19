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
            $table->text('household_head_opinion')->nullable();
            $table->text('legal_owner_opinion')->nullable();
            $table->text('guardian_opinion')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('residence_declarations', function (Blueprint $table) {
            $table->dropColumn(['household_head_opinion', 'legal_owner_opinion', 'guardian_opinion']);
        });
    }
};
