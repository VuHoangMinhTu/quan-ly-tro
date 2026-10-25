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
            $table->date('legal_owner_birth_date')->nullable()->after('legal_owner_birth_year');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('boarding_house_residence_profiles', function (Blueprint $table) {
            $table->dropColumn('legal_owner_birth_date');
        });
    }
};
