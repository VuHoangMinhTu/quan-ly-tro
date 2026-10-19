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
        Schema::table('boarding_house_residence_profiles', function (Blueprint $table) {
            $table->string('residence_authority_name')->nullable();
            $table->string('legal_owner_name')->nullable();
            $table->unsignedSmallInteger('legal_owner_birth_year')->nullable();
            $table->string('legal_owner_permanent_address', 500)->nullable();
            $table->string('legal_owner_identity_number', 30)->nullable();
            $table->string('legal_owner_phone', 20)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('boarding_house_residence_profiles', function (Blueprint $table) {
            $table->dropColumn(['residence_authority_name', 'legal_owner_name', 'legal_owner_birth_year', 'legal_owner_permanent_address', 'legal_owner_identity_number', 'legal_owner_phone']);
        });
    }
};
