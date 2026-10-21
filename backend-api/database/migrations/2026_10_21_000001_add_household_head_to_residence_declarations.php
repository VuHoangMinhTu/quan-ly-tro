<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('residence_declarations', function (Blueprint $table): void {
            $table->string('household_head_mode', 20)->nullable();
            $table->string('household_head_name')->nullable();
            $table->string('household_head_identity_number', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('residence_declarations', function (Blueprint $table): void {
            $table->dropColumn(['household_head_mode', 'household_head_name', 'household_head_identity_number']);
        });
    }
};
