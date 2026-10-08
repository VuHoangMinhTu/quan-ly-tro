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
        Schema::create('room_tenants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->date('move_in_date');
            $table->date('move_out_date')->nullable();
            $table->enum('status', ['ACTIVE', 'MOVED_OUT']);
            $table->timestamps();

            $table->index('room_id');
            $table->index('tenant_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_tenants');
    }
};
