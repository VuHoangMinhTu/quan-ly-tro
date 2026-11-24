<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_renewals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->date('old_end_date');
            $table->date('new_end_date');
            $table->string('status_before', 20);
            $table->text('reason');
            $table->foreignId('extended_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('agreement_confirmed_at');
            $table->timestamps();
            $table->index(['contract_id', 'created_at']);
        });
    }

    public function down(): void
    {
        // Do not silently drop historical evidence during a code rollback.
        throw new \LogicException('Contract renewal history must be preserved.');
    }
};
