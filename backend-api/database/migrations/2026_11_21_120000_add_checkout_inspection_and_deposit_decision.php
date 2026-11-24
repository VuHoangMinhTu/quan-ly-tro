<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_handover_reports', function (Blueprint $table): void {
            $table->json('inspection')->nullable();
        });
        Schema::table('contract_deposit_entries', function (Blueprint $table): void {
            $table->text('decision_reason')->nullable();
        });
    }

    public function down(): void
    {
        throw new LogicException('Checkout inspection and deposit decision evidence must be preserved; rollback is code-only.');
    }
};
