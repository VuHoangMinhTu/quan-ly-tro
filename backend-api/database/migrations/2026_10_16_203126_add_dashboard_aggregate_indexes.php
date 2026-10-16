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
        Schema::table('room_tenants', fn (Blueprint $table) => $table->index(['room_id', 'status', 'tenant_id'], 'room_tenants_dashboard_occupancy_index'));
        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['room_id', 'billing_period', 'status'], 'invoices_dashboard_period_index');
            $table->index(['status', 'due_date', 'room_id'], 'invoices_dashboard_overdue_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_dashboard_period_index');
            $table->dropIndex('invoices_dashboard_overdue_index');
        });
        Schema::table('room_tenants', fn (Blueprint $table) => $table->dropIndex('room_tenants_dashboard_occupancy_index'));
    }
};
