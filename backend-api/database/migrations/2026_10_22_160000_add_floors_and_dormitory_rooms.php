<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('floors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boarding_house_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['boarding_house_id', 'name']);
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->foreignId('floor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('room_type')->default('PRIVATE_ROOM');
        });

        Schema::table('beds', function (Blueprint $table) {
            $table->decimal('monthly_rent', 15, 2)->nullable();
            $table->string('status')->default('AVAILABLE');
            $table->text('note')->nullable();
        });

        Schema::table('room_tenants', function (Blueprint $table) {
            $table->foreignId('bed_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('room_tenants', fn (Blueprint $table) => $table->dropConstrainedForeignId('bed_id'));
        Schema::table('beds', fn (Blueprint $table) => $table->dropColumn(['monthly_rent', 'status', 'note']));
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('floor_id');
            $table->dropColumn('room_type');
        });
        Schema::dropIfExists('floors');
    }
};
