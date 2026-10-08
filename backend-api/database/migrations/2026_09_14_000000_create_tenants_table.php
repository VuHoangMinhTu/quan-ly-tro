<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 10)->nullable();
            $table->string('identity_number', 30)->nullable();
            $table->string('identity_issue_place')->nullable();
            $table->date('identity_issue_date')->nullable();
            $table->string('permanent_address', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
