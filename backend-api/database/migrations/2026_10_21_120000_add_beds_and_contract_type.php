<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->string('bed_code', 50);
            $table->string('floor_label', 100)->nullable();
            $table->timestamps();
            $table->unique(['room_id', 'bed_code']);
        });

        Schema::table('contracts', function (Blueprint $table): void {
            $table->string('contract_type', 20)->default('ROOM_RENTAL');
            $table->foreignId('bed_id')->nullable()->constrained('beds')->restrictOnDelete();
            $table->index(['bed_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bed_id');
            $table->dropColumn('contract_type');
        });
        Schema::dropIfExists('beds');
    }
};
