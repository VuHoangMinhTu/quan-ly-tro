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
        Schema::table('boarding_houses', function (Blueprint $table) {
            $table->unsignedTinyInteger('default_invoice_issue_day_from')->nullable();
            $table->unsignedTinyInteger('default_invoice_issue_day_to')->nullable();
            $table->unsignedTinyInteger('default_payment_due_day')->nullable();
        });
        Schema::table('contracts', function (Blueprint $table) {
            $table->date('start_date')->nullable()->change();
            $table->decimal('monthly_rent', 15, 2)->nullable()->change();
            $table->foreignId('lessor_representative_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('invoice_issue_day_from')->nullable();
            $table->unsignedTinyInteger('invoice_issue_day_to')->nullable();
            $table->unsignedTinyInteger('payment_due_day')->nullable();
            // Draft selection is not occupancy. Only activation writes room_tenants.
            $table->json('occupant_tenant_ids')->nullable();
            $table->json('signing_snapshot')->nullable();
            $table->timestamp('finalized_at')->nullable();
        });
        Schema::table('room_tenants', function (Blueprint $table) {
            $table->foreignId('contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->index(['contract_id', 'status']);
        });
        Schema::create('contract_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service_name');
            $table->string('service_type', 30);
            $table->string('billing_method', 30);
            $table->string('unit')->nullable();
            $table->decimal('base_price', 15, 2)->nullable();
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->json('pricing_snapshot')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['contract_id', 'service_id']);
        });
        Schema::create('contract_utility_openings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('utility_meter_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service_name');
            $table->string('unit')->nullable();
            $table->decimal('opening_reading', 15, 2);
            $table->date('reading_date');
            $table->timestamps();
            $table->unique(['contract_id', 'utility_meter_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Never silently discard finalized evidence when rolling back in production.
        if (DB::table('contracts')->whereNotNull('signing_snapshot')->exists()
            || DB::table('contracts')->whereNull('start_date')->exists()
            || DB::table('contracts')->whereNull('monthly_rent')->exists()) {
            throw new RuntimeException('Contract upgrade contains snapshots or incomplete drafts. Use a forward migration instead.');
        }
        Schema::dropIfExists('contract_utility_openings');
        Schema::dropIfExists('contract_services');
        Schema::table('room_tenants', function (Blueprint $table) {
            $table->dropIndex(['contract_id', 'status']);
            $table->dropConstrainedForeignId('contract_id');
        });
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lessor_representative_user_id');
            $table->dropColumn(['invoice_issue_day_from', 'invoice_issue_day_to', 'payment_due_day', 'occupant_tenant_ids', 'signing_snapshot', 'finalized_at']);
            $table->date('start_date')->nullable(false)->change();
            $table->decimal('monthly_rent', 15, 2)->nullable(false)->change();
        });
        Schema::table('boarding_houses', fn (Blueprint $table) => $table->dropColumn(['default_invoice_issue_day_from', 'default_invoice_issue_day_to', 'default_payment_due_day']));
    }
};
