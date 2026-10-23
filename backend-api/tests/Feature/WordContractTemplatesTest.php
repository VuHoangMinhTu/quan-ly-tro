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
        $this->assertSame(1, substr_count($content, '{{handover_checkin_table}}'));
        $this->assertStringContainsString('<p class="handover-heading"><strong>BIÊN BẢN BÀN GIAO HIỆN TRẠNG PHÒNG: {{room.room_code}}</strong></p>', $content);
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
        $this->assertSame(1, substr_count($rendered, '<table'));
        $this->assertStringContainsString('<p class="handover-heading"><strong>BIÊN BẢN BÀN GIAO HIỆN TRẠNG PHÒNG:', $rendered);
        $this->assertSame(15, substr_count($rendered, '<tr>'));
        $this->assertSame(4, substr_count($rendered, '<th>'));
        $this->assertStringContainsString('<th>Ngày nhận: 22/10/2026</th>', $rendered);
        $this->assertStringContainsString('<th>Ngày trả: …………</th>', $rendered);
        $this->assertStringContainsString('<td>Giường</td>', $rendered);
        $this->assertStringContainsString('Mới, tốt ☑ &nbsp; Cũ, hư ☐', $rendered);
        $this->assertStringNotContainsString('Chưa cập nhật', $rendered);

        $snapshot['contract']['start_date'] = '2026-11-01';
        $snapshot['contract']['end_date'] = '2027-10-31';
        $inclusiveYear = app(ContractTemplateRenderer::class)->render($content, $snapshot);
        $this->assertStringContainsString('Thời gian hợp đồng là: 12 tháng', $inclusiveYear);
    }

    public function test_stored_room_template_with_legacy_static_table_renders_only_dynamic_handover(): void
    {
        $content = file_get_contents(resource_path('views/contracts-room-rental-word.html'));
        $legacyRows = implode('', array_map(
            fn (int $number) => '<tr><td>'.$number.'</td><td>LEGACY STATIC ROW '.$number.'</td><td>Mới, tốt ☐</td><td>Cũ, hư ☐</td></tr>',
            range(1, 13)
        ));
        $legacyTable = '<table><thead><tr><th>STT</th><th>Thiết bị và dụng cụ</th><th>Ngày nhận</th><th>Ngày trả</th></tr></thead>'
            .'<tbody>'.$legacyRows.'</tbody></table>';
        $content = str_replace('{{handover_checkin_table}}', $legacyTable.'{{handover_checkin_table}}', $content);
        $snapshot = app(ContractTemplateVariableRegistry::class)->sample();
        $snapshot['contract']['contract_type'] = 'ROOM_RENTAL';
        $snapshot['handover_checkin'] = [['name' => 'Máy lạnh', 'condition' => 'GOOD']];

        $rendered = app(ContractTemplateRenderer::class)->render($content, $snapshot);

        $this->assertSame(1, substr_count($rendered, 'BIÊN BẢN BÀN GIAO HIỆN TRẠNG PHÒNG'));
        $this->assertSame(1, substr_count($rendered, '<table'));
        $this->assertSame(1, substr_count($rendered, 'class="contract-handover-table"'));
        $this->assertStringNotContainsString('LEGACY STATIC ROW', $rendered);
        $this->assertStringContainsString('<td>Máy lạnh<br>Remote:', $rendered);
        $this->assertStringContainsString('Mới, tốt ☑', $rendered);
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
            $this->assertSame($type === 'ROOM_RENTAL' ? 16 : 7, substr_count($rendered, '<tr>'));
            $this->assertStringContainsString('<td>Tủ lạnh</td>', $rendered);
            $this->assertStringContainsString($type === 'ROOM_RENTAL' ? 'Cũ, hư ☑<br>Trầy cửa' : '<td>Hư hỏng</td><td>Trầy cửa</td>', $rendered);
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
        $this->assertSame(14, substr_count($rendered, '<tr>'));
        $this->assertSame(13, substr_count($rendered, 'Mới, tốt ☐ &nbsp; Cũ, hư ☐<br>……………………') / 2);
        $this->assertStringNotContainsString('<td>Giường</td>', $rendered);
    }

    public function test_room_rental_handover_maps_check_in_and_future_check_out_into_the_same_word_table(): void
    {
        $snapshot = app(ContractTemplateVariableRegistry::class)->sample();
        $snapshot['contract']['contract_type'] = 'ROOM_RENTAL';
        $snapshot['contract']['start_date'] = '2026-10-22';
        $snapshot['handover_checkin'] = [
            ['name' => 'Khóa/chìa khóa', 'quantity' => 2, 'condition' => 'GOOD'],
            ['name' => 'Máy lạnh', 'remote_count' => 1, 'condition' => 'GOOD', 'note' => 'Remote đầy đủ'],
        ];
        $snapshot['handover_checkout_date'] = '2027-10-22';
        $snapshot['handover_checkout'] = [
            ['name' => 'Máy lạnh', 'condition' => 'DAMAGED', 'note' => 'Remote bị hỏng'],
        ];

        $rendered = app(ContractTemplateRenderer::class)->render(
            file_get_contents(resource_path('views/contracts-room-rental-word.html')), $snapshot
        );

        $this->assertSame(1, substr_count($rendered, 'class="contract-handover-table"'));
        $this->assertSame(14, substr_count($rendered, '<tr>'));
        $this->assertSame(4, substr_count($rendered, '<th>'));
        $this->assertStringContainsString('<th>Ngày nhận: 22/10/2026</th>', $rendered);
        $this->assertStringContainsString('<th>Ngày trả: 22/10/2027</th>', $rendered);
        $this->assertStringContainsString('Khóa cửa<br>Số lượng chìa: 2</td><td>Mới, tốt ☑ &nbsp; Cũ, hư ☐', $rendered);
        $this->assertStringContainsString('Máy lạnh<br>Remote: 1</td><td>Mới, tốt ☑ &nbsp; Cũ, hư ☐<br>Remote đầy đủ</td><td>Mới, tốt ☐ &nbsp; Cũ, hư ☑<br>Remote bị hỏng', $rendered);
        $this->assertStringNotContainsString('<th>Số lượng</th>', $rendered);
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
