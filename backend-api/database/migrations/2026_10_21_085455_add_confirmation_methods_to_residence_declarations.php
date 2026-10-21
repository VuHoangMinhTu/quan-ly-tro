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
            $table->string('household_head_confirmation_method', 30)->nullable();
            $table->string('legal_owner_confirmation_method', 30)->nullable();
            $table->string('guardian_confirmation_method', 30)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('residence_declarations', function (Blueprint $table) {
            $table->dropColumn(['household_head_confirmation_method', 'legal_owner_confirmation_method', 'guardian_confirmation_method']);
        });
    }
};
