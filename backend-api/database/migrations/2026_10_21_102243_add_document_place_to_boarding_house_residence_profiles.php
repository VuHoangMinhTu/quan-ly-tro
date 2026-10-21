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
            $table->string('document_place')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('boarding_house_residence_profiles', function (Blueprint $table) {
            $table->dropColumn('document_place');
        });
    }
};
