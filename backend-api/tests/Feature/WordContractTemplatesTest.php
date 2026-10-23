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
        $this->assertStringContainsString('{{handover_checkin_table}}', $content);
        $this->assertStringNotContainsString('<table>', $content);
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
        $this->assertStringContainsString('{{bed.bed_code}}', $content);
        $this->assertStringContainsString('{{handover_checkin_table}}', $content);
        $this->assertStringNotContainsString('<table>', $content);
        $this->assertStringContainsString('class="page-break"', $content);
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

    public function test_room_rental_article_four_uses_agreed_service_prices_from_snapshot(): void
    {
        $content = ContractTemplate::query()->where('scope_key', 'GLOBAL')
            ->where('template_key', 'ROOM_RENTAL_STANDARD')->where('is_active', true)->sole()->content_html;
        $this->assertStringContainsString('{{room_rental_services}}', $content);

        $rendered = app(ContractTemplateRenderer::class)->render($content, [
            ...app(ContractTemplateVariableRegistry::class)->sample(),
            'contract' => ['contract_type' => 'ROOM_RENTAL'],
            'services' => [
                ['service_name' => 'Điện', 'service_type' => 'ELECTRICITY', 'billing_method' => 'PER_UNIT', 'base_price' => '3800.00', 'unit' => 'kWh', 'is_active' => true],
                ['service_name' => 'Nước', 'service_type' => 'WATER', 'billing_method' => 'FIXED', 'base_price' => '150000.00', 'unit' => 'tháng', 'is_active' => true],
                ['service_name' => 'Phí quản lý', 'service_type' => 'SERVICE', 'billing_method' => 'FIXED', 'base_price' => '25000.00', 'unit' => 'tháng', 'is_active' => true],
                ['service_name' => 'Phí vệ sinh cũ', 'service_type' => 'OTHER', 'billing_method' => 'FIXED', 'base_price' => '30000.00', 'unit' => 'tháng', 'is_active' => true],
            ],
        ]);

        $this->assertStringContainsString('☑ Tiền điện : 3.800 đồng / kWh', $rendered);
        $this->assertStringContainsString('☑ Tiền nước : 150.000 đồng / tháng', $rendered);
        $this->assertStringContainsString('☑ Dịch vụ : Phí quản lý — 25.000 đồng / tháng; Phí vệ sinh cũ — 30.000 đồng / tháng', $rendered);
        $this->assertStringContainsString('☐ Internet : …', $rendered);
    }

    public function test_room_rental_word_fills_known_dates_and_renders_only_one_handover_table(): void
    {
        $content = file_get_contents(resource_path('views/contracts-room-rental-word.html'));
        $snapshot = app(ContractTemplateVariableRegistry::class)->sample();
        $snapshot['contract']['contract_type'] = 'ROOM_RENTAL';
        $snapshot['contract']['signed_date'] = '2026-10-22';
        $snapshot['contract']['start_date'] = '2026-10-22';
        $snapshot['contract']['end_date'] = '2026-12-22';
        $snapshot['handover_checkin'] = [['name' => 'Giường', 'quantity' => 1, 'condition' => 'GOOD']];

        $rendered = app(ContractTemplateRenderer::class)->render($content, $snapshot);

        $this->assertStringContainsString('ngày 22 tháng 10 năm 2026', $rendered);
        $this->assertStringContainsString('Thời gian hợp đồng là: 2 tháng', $rendered);
        $this->assertSame(1, substr_count($rendered, 'class="contract-handover-table"'));
        $this->assertStringContainsString('<td>Giường</td>', $rendered);
        $this->assertStringContainsString('<td>1</td><td>Tốt</td>', $rendered);
        $this->assertStringNotContainsString('Chưa cập nhật', $rendered);

        $snapshot['contract']['start_date'] = '2026-11-01';
        $snapshot['contract']['end_date'] = '2027-10-31';
        $inclusiveYear = app(ContractTemplateRenderer::class)->render($content, $snapshot);
        $this->assertStringContainsString('Thời gian hợp đồng là: 12 tháng', $inclusiveYear);
    }

    public function test_word_appendix_uses_only_confirmed_check_in_items_for_both_contract_types(): void
    {
        $items = [
            ['name' => 'Nệm', 'quantity' => 1, 'condition' => 'GOOD', 'note' => null],
            ['name' => 'Tủ', 'quantity' => 1, 'condition' => 'DAMAGED', 'note' => 'Trầy cửa'],
            ['name' => 'Khóa/chìa khóa', 'quantity' => 2, 'condition' => 'GOOD', 'note' => null],
            ['name' => 'Đèn/ổ cắm', 'quantity' => 1, 'condition' => 'GOOD', 'note' => null],
            ['name' => 'Máy lạnh', 'quantity' => 1, 'condition' => 'GOOD', 'note' => null],
            ['name' => 'Tủ lạnh', 'quantity' => 1, 'condition' => 'OTHER', 'note' => 'Đã dùng'],
        ];

        foreach (['ROOM_RENTAL' => 'contracts-room-rental-word.html', 'DORM_BED' => 'contracts-dorm-bed-word.html'] as $type => $filename) {
            $snapshot = app(ContractTemplateVariableRegistry::class)->sample();
            $snapshot['contract']['contract_type'] = $type;
            $snapshot['handover_checkin'] = $items;

            $rendered = app(ContractTemplateRenderer::class)->render(file_get_contents(resource_path('views/'.$filename)), $snapshot);

            $this->assertSame(1, substr_count($rendered, 'class="contract-handover-table"'));
            $this->assertSame(7, substr_count($rendered, '<tr>'));
            $this->assertStringContainsString('<td>Tủ lạnh</td>', $rendered);
            $this->assertStringContainsString('<td>Hư hỏng</td><td>Trầy cửa</td>', $rendered);
            $this->assertStringNotContainsString('<td>Giường</td>', $rendered);
            $this->assertStringNotContainsString('Chưa ký', $rendered);
        }
    }

    public function test_word_appendix_without_check_in_is_one_empty_table(): void
    {
        $snapshot = app(ContractTemplateVariableRegistry::class)->sample();
        $snapshot['contract']['contract_type'] = 'ROOM_RENTAL';

        $rendered = app(ContractTemplateRenderer::class)->render(file_get_contents(resource_path('views/contracts-room-rental-word.html')), $snapshot);

        $this->assertSame(1, substr_count($rendered, 'class="contract-handover-table"'));
        $this->assertSame(2, substr_count($rendered, '<tr>'));
        $this->assertStringNotContainsString('<td>Giường</td>', $rendered);
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
