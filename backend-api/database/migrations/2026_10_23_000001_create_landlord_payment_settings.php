<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landlord_payment_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('landlord_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->timestamps();
        });

        Schema::create('landlord_payos_configs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('landlord_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('client_id');
            $table->text('api_key');
            $table->text('checksum_key');
            $table->uuid('webhook_uuid')->unique()->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::table('payos_payment_requests', function (Blueprint $table): void {
            // NULL identifies pre-migration requests issued through the legacy merchant.
            $table->foreignId('payos_config_id')->nullable()->constrained('landlord_payos_configs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payos_payment_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payos_config_id');
        });
        Schema::dropIfExists('landlord_payos_configs');
        Schema::dropIfExists('landlord_payment_profiles');
    }
};
