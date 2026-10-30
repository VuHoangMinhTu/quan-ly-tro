<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('contract_access', 8)->default('FULL');
        });
        Schema::table('contracts', function (Blueprint $table): void {
            $table->boolean('is_demo')->default(false);
            $table->json('demo_document_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contracts', fn (Blueprint $table) => $table->dropColumn(['is_demo', 'demo_document_snapshot']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('contract_access'));
    }
};
