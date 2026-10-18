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
        Schema::create('contract_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('scope_key', 40);
            $table->string('template_key', 80);
            $table->string('name');
            $table->unsignedInteger('version');
            $table->longText('content_html');
            $table->boolean('is_active')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // A nullable landlord FK alone would not enforce global uniqueness in MySQL.
            $table->unique(['scope_key', 'template_key', 'version'], 'contract_templates_scope_version_unique');
        });
        Schema::table('contracts', fn (Blueprint $table) => $table->foreignId('contract_template_id')->nullable()->constrained()->restrictOnDelete());
        Schema::create('contract_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('contract_template_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('template_key', 80)->nullable();
            $table->unsignedInteger('template_version')->nullable();
            $table->string('template_name');
            $table->longText('rendered_html');
            $table->json('source_snapshot');
            $table->char('document_hash', 64);
            $table->timestamp('finalized_at');
            $table->string('pdf_path')->nullable();
            $table->char('pdf_hash', 64)->nullable();
            $table->timestamps();
        });
        DB::table('contract_templates')->insert([
            'scope_key' => 'GLOBAL', 'landlord_id' => null, 'template_key' => 'BEEHOUSE_DEMO',
            'name' => 'Mẫu minh họa BeeHouse — cần bổ sung điều khoản', 'version' => 1, 'is_active' => true,
            'content_html' => '<h1 class="text-center">THÔNG TIN HỢP ĐỒNG THUÊ PHÒNG</h1><p><strong>Mẫu minh họa tối giản, chưa bao gồm nội dung điều khoản pháp lý từ mẫu Word của chủ trọ.</strong></p><p>Mã hợp đồng: {{contract.code}}</p><h2>Thông tin hai bên</h2><p>Bên A: {{lessor.full_name}} — {{lessor.title}} — {{lessor.phone}}</p><p>Bên B: {{tenant.full_name}} — Số định danh: {{tenant.identity_number}}</p><p>Điện thoại: {{tenant.phone}}. Địa chỉ thường trú: {{tenant.permanent_address}}</p><h2>Phòng và thời hạn</h2><p>Nhà trọ: {{boarding_house.name}} — {{boarding_house.address}}</p><p>Phòng {{room.room_code}}. Diện tích: {{room.area}}</p><p>Từ {{contract.start_date}} đến {{contract.end_date}}</p><h2>Tài chính</h2><p>Tiền thuê: {{contract.monthly_rent}}. Tiền cọc thỏa thuận: {{contract.deposit}}</p><p>Ngày thanh toán hàng tháng: {{contract.payment_due_day}}</p><h2>Dịch vụ thỏa thuận</h2>{{services_table}}<h2>Người ở</h2>{{occupants_table}}<p>Chưa có chữ ký. Việc chốt dữ liệu không đồng nghĩa các bên đã ký hợp đồng.</p>',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('contract_documents')->exists() || DB::table('contract_templates')->whereNotNull('landlord_id')->exists()) {
            throw new RuntimeException('Contract templates/documents contain user evidence. Use a forward migration.');
        }
        Schema::dropIfExists('contract_documents');
        Schema::table('contracts', fn (Blueprint $table) => $table->dropConstrainedForeignId('contract_template_id'));
        Schema::dropIfExists('contract_templates');
    }
};
