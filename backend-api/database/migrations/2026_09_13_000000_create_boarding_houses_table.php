<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boarding_houses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('address', 500);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boarding_houses');
    }
};
