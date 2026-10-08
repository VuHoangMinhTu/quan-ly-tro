<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('payment_method', 30)->change();
            $table->string('payment_source', 30)->default('MANUAL')->after('payment_method');
            $table->string('external_reference', 255)->nullable()->after('reference_code');
            $table->unique(['payment_method', 'external_reference'], 'payments_method_external_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_method_external_reference_unique');
            $table->dropColumn(['payment_source', 'external_reference']);
            $table->enum('payment_method', ['CASH', 'BANK_TRANSFER', 'CARD', 'OTHER'])->change();
        });
    }
};
