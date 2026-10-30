<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('house_creation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('payload_hash', 64);
            $table->unsignedBigInteger('house_id')->nullable();
            $table->unsignedInteger('rooms_count')->nullable();
            $table->unique(['user_id', 'request_key']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('house_creation_requests')->exists()) {
            throw new RuntimeException('Creation receipts must be preserved to keep retries idempotent. Roll back application code without dropping this table.');
        }
        Schema::dropIfExists('house_creation_requests');
    }
};
