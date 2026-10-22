<?php

namespace Tests\Feature;

use App\Models\ContractTemplate;
use App\Services\ContractTemplateRenderer;
use App\Services\ContractTemplateVariableRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WordContractTemplatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_room_rental_word_template_keeps_all_twelve_articles_and_handover_appendix(): void
    {
        $content = ContractTemplate::query()->where('scope_key', 'GLOBAL')
            ->where('template_key', 'ROOM_RENTAL_STANDARD')->where('is_active', true)->sole()->content_html;

        $this->assertArticleOrder($content, 12);
        $this->assertStringContainsString('BIÊN BẢN BÀN GIAO HIỆN TRẠNG PHÒNG', $content);
        $this->assertStringContainsString('Tủ, kệ bếp', $content);
        $this->assertStringContainsString('{{handover_checkin_table}}', $content);
        $this->assertSame(14, substr_count($content, '<tr>'));
        $this->assertStringContainsString('Tiền thuê hàng tháng là: {{contract.monthly_rent_value}} VNĐ', $content);
        $this->assertStringContainsString('Khách hàng đặt cọc số tiền: {{contract.deposit_value}} VNĐ', $content);
        $this->assertStringContainsString('<div class="document-signatures"></div>', $content);
        $snapshot = app(ContractTemplateRenderer::class)->render($content, [
            ...app(ContractTemplateVariableRegistry::class)->sample(),
            'contract' => ['contract_type' => 'ROOM_RENTAL', 'monthly_rent' => 3800000, 'deposit_amount' => 7600000],
        ]);
        $this->assertStringContainsString('3.800.000 VNĐ', $snapshot);
        $this->assertStringContainsString('Người đại diện (Họ tên, chữ ký)', $snapshot);
        $this->assertStringNotContainsString('1041333679', $content);
        $this->assertStringNotContainsString('NGO DUC BINH', $content);
    }

    public function test_dorm_bed_word_template_keeps_all_nine_articles_and_six_handover_items(): void
    {
        $content = ContractTemplate::query()->where('scope_key', 'GLOBAL')
            ->where('template_key', 'DORM_BED_STANDARD')->where('is_active', true)->sole()->content_html;

        $this->assertArticleOrder($content, 9);
        $this->assertStringContainsString('PHỤ LỤC: BIÊN BẢN BÀN GIAO HIỆN TRẠNG TRANG THIẾT BỊ', $content);
        $this->assertStringContainsString('Máy lạnh phòng + Remote điều hòa', $content);
        $this->assertStringContainsString('{{bed.bed_code}}', $content);
        $this->assertStringContainsString('{{handover_checkin_table}}', $content);
        $this->assertSame(7, substr_count($content, '<tr>'));
        $this->assertStringContainsString('{{tenant.gender_checkboxes}}', $content);
        $this->assertStringContainsString('{{contract.monthly_rent_value}} VNĐ/tháng', $content);
        $snapshot = app(ContractTemplateRenderer::class)->render($content, [
            ...app(ContractTemplateVariableRegistry::class)->sample(),
            'contract' => ['contract_type' => 'DORM_BED', 'monthly_rent' => 1500000, 'deposit_amount' => 1500000],
            'tenant' => ['gender' => 'FEMALE'],
        ]);
        $this->assertStringContainsString('☐ Nam ☒ Nữ', $snapshot);
        $this->assertStringContainsString('(Ký và ghi rõ họ tên)', $snapshot);
        $this->assertStringNotContainsString('115M', $content);
        $this->assertStringNotContainsString('0901 7116 368', $content);
    }

    private function assertArticleOrder(string $content, int $count): void
    {
        $previous = -1;
        for ($number = 1; $number <= $count; $number++) {
            $position = strpos($content, 'ĐIỀU '.$number.':');
            $this->assertNotFalse($position, 'Thiếu điều '.$number);
            $this->assertGreaterThan($previous, $position, 'Sai thứ tự điều '.$number);
            $previous = $position;
        }
    }
}
