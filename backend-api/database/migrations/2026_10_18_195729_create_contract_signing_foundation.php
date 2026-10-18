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
        Schema::create('contract_document_signings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_document_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status', 30)->default('NOT_STARTED');
            $table->timestamp('signatures_completed_at')->nullable();
            $table->longText('signed_rendered_html')->nullable();
            $table->char('signed_document_hash', 64)->nullable();
            $table->string('signed_pdf_path')->nullable();
            $table->char('signed_pdf_hash', 64)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('contract_signing_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_document_id')->constrained()->restrictOnDelete();
            $table->string('signer_role', 20);
            // Revoked/declined/expired requests release this unique active slot.
            $table->string('active_key', 80)->nullable()->unique();
            $table->foreignId('signer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('signer_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('signer_name_snapshot');
            $table->string('delivery_channel', 20)->default('EMAIL');
            $table->string('delivery_destination_snapshot');
            $table->char('token_hash', 64)->nullable()->unique();
            $table->timestamp('token_expires_at');
            $table->string('otp_hash')->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->unsignedSmallInteger('otp_attempts')->default(0);
            $table->timestamp('otp_verified_at')->nullable();
            $table->char('verification_hash', 64)->nullable();
            $table->timestamp('last_otp_sent_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('status', 30)->default('PENDING');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['contract_document_id', 'signer_role', 'id'], 'signing_requests_document_role');
        });
        Schema::create('contract_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_document_id')->constrained()->restrictOnDelete();
            $table->foreignId('signing_request_id')->unique()->constrained('contract_signing_requests')->restrictOnDelete();
            $table->string('signer_role', 20);
            $table->foreignId('signer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('signer_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('signer_name_snapshot');
            $table->string('signature_method', 40)->default('DRAWN');
            $table->string('verification_method', 40);
            $table->string('signature_file_path');
            $table->char('signature_file_hash', 64);
            $table->char('document_hash', 64);
            $table->string('consent_version', 40);
            $table->text('consent_text');
            $table->timestamp('signed_at');
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();
            $table->unique(['contract_document_id', 'signer_role'], 'signatures_document_role_unique');
        });
        Schema::create('contract_signature_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_document_id')->constrained()->restrictOnDelete();
            $table->foreignId('signing_request_id')->nullable()->constrained('contract_signing_requests')->restrictOnDelete();
            $table->string('actor_type', 20);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('event', 40);
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['contract_document_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('contract_signature_events')->exists()
            || DB::table('contract_signatures')->exists()) {
            throw new RuntimeException('Signing evidence must be preserved. Use a forward migration.');
        }
        Schema::dropIfExists('contract_signature_events');
        Schema::dropIfExists('contract_signatures');
        Schema::dropIfExists('contract_signing_requests');
        Schema::dropIfExists('contract_document_signings');
    }
};
