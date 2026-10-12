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
        Schema::table('payos_payment_requests', function (Blueprint $table): void {
            $table->text('description')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // A rollback must never silently truncate a historical QR snapshot.
        foreach (DB::table('payos_payment_requests')->select('description')->whereNotNull('description')->cursor() as $request) {
            if (mb_strlen($request->description) > 255) {
                throw new RuntimeException('Cannot restore VARCHAR(255) while longer payOS descriptions exist.');
            }
        }

        Schema::table('payos_payment_requests', function (Blueprint $table): void {
            $table->string('description', 255)->nullable()->change();
        });
    }
};
