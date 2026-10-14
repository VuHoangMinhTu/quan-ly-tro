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
        Schema::create('boarding_house_managers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('boarding_house_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['boarding_house_id', 'user_id']);
            $table->index(['user_id', 'boarding_house_id']);
        });
        Schema::create('boarding_house_residence_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('boarding_house_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('household_head_name')->nullable();
            $table->string('household_head_identity_number', 30)->nullable();
            $table->timestamps();
        });
        Schema::create('residence_declarations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('landlord_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boarding_house_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('declarant_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('authority_name')->nullable();
            $table->string('relationship_to_household_head')->nullable();
            $table->text('request_content')->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();
            $table->index(['boarding_house_id', 'status']);
            $table->index(['room_id', 'created_at']);
        });
        Schema::create('residence_declaration_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('residence_declaration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('relationship_to_household_head')->nullable();
            $table->timestamps();
            $table->unique(['residence_declaration_id', 'tenant_id'], 'residence_member_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('residence_declaration_members');
        Schema::dropIfExists('residence_declarations');
        Schema::dropIfExists('boarding_house_residence_profiles');
        Schema::dropIfExists('boarding_house_managers');
    }
};
