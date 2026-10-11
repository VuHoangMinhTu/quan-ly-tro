<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            $table->decimal('monthly_rent', 15, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        // NULL means unconfigured, not a zero-price room. Never backfill it with 0.
        if (DB::table('rooms')->whereNull('monthly_rent')->exists()) {
            throw new RuntimeException('Cannot restore NOT NULL while rooms have unconfigured rent.');
        }

        Schema::table('rooms', function (Blueprint $table): void {
            $table->decimal('monthly_rent', 15, 2)->nullable(false)->change();
        });
    }
};
