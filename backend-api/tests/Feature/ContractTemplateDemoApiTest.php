<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContractTemplateDemoApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_requires_login_and_rejects_unknown_types(): void
    {
        $this->getJson('/api/contract-templates/demos/room')->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->getJson('/api/contract-templates/demos/unknown')->assertNotFound();
    }

    public function test_both_demos_use_source_templates_without_database_reads_or_writes_and_match_across_users(): void
    {
        $first = User::factory()->create(['name' => 'Tên tài khoản riêng không được lộ']);
        $second = User::factory()->create(['name' => 'Tài khoản khác']);
        $this->actingAs($first, 'sanctum');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $room = $this->getJson('/api/contract-templates/demos/room')->assertOk()->assertJsonPath('data.is_demo', true)->json('data');
        $dorm = $this->getJson('/api/contract-templates/demos/dorm')->assertOk()->assertJsonPath('data.is_demo', true)->json('data');
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->actingAs($second, 'sanctum');
        $this->assertSame($room, $this->getJson('/api/contract-templates/demos/room')->assertOk()->json('data'));
        $this->assertSame($dorm, $this->getJson('/api/contract-templates/demos/dorm')->assertOk()->json('data'));

        foreach ([[$room, 12, 13], [$dorm, 9, 6]] as [$demo, $articles, $items]) {
            $html = $demo['rendered_html'];
            $dom = new DOMDocument;
            $dom->loadHTML('<meta charset="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $this->assertCount($articles, $dom->getElementsByTagName('h2'));
            $this->assertCount($items + 1, $dom->getElementsByTagName('tr'));
            $this->assertStringNotContainsString('{{', $html);
            $this->assertStringNotContainsString('Chưa cập nhật', $html);
            $this->assertStringNotContainsString('Chưa ký', $html);
            $this->assertStringNotContainsString($first->name, $html);
            $this->assertStringContainsString('signature-handwritten-space', $html);
            $this->assertStringContainsString('052098006132', $html);
            $this->assertStringContainsString('Minh Tú', $html);
            $this->assertStringContainsString('MB Bank', $html);
            $this->assertStringContainsString('0385528994', $html);
            $this->assertStringNotContainsString('(không thanh toán)', $html);
            $this->assertStringNotContainsString('không giao dịch', $html);
            $this->assertStringNotContainsString('DEMO-', $html);
            $this->assertStringContainsString('3.800', $html);
            $this->assertStringContainsString('100.000', $html);
            $this->assertStringContainsString('150.000', $html);
        }
        $this->assertStringContainsString('Bùi Quang Duy', $room['rendered_html']);
        $this->assertStringContainsString('12 tháng', $room['rendered_html']);
        $this->assertStringContainsString('3.000.000 VNĐ', $room['rendered_html']);
        $this->assertStringContainsString('Bùi Quang Duy', $dorm['rendered_html']);
        $this->assertStringContainsString('6 tháng', $dorm['rendered_html']);
        $this->assertStringContainsString('1.500.000 VNĐ/tháng', $dorm['rendered_html']);
        $this->assertStringContainsString('[x] Gói tính theo định mức/tiêu thụ:', $dorm['rendered_html']);
        $this->assertStringContainsString('Giường: G01', $dorm['rendered_html']);
        $this->assertSame('BeeHouse_HopDong_PhongTro_DEMO.pdf', $room['filename']);
        $this->assertSame('BeeHouse_HopDong_KTX_DEMO.pdf', $dorm['filename']);
        foreach (['contracts', 'contract_documents', 'room_tenants', 'invoices', 'payments', 'rooms', 'beds', 'tenants', 'landlords'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
