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

        foreach ([$room, $dorm] as $demo) {
            $html = $demo['rendered_html'];
            $dom = new DOMDocument;
            $dom->loadHTML('<meta charset="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $this->assertCount(2, $dom->getElementsByTagName('h2'));
            $this->assertStringContainsString('contract-handover-table', $html);
            $this->assertSame(2, substr_count($html, 'class="signature-handwritten-space"'));
            $this->assertStringContainsString('ĐIỀU 2:', $html);
            $this->assertStringNotContainsString('ĐIỀU 3:', $html);
            $this->assertStringContainsString('KHÔNG PHẢI HỢP ĐỒNG ĐẦY ĐỦ', $html);
            $this->assertStringNotContainsString('{{', $html);
            $this->assertStringContainsString('Người thuê minh họa', $html);
            foreach (['052098006132', '0385528994', '0983407941', 'VU HOANG MINH TU', 'Minh Tú', 'Bùi Quang Duy'] as $privateValue) {
                $this->assertStringNotContainsString($privateValue, json_encode($demo, JSON_UNESCAPED_UNICODE));
            }
        }
        $this->assertStringContainsString('3.000.000 VNĐ', $room['rendered_html']);
        $this->assertStringContainsString('1.500.000 VNĐ', $dorm['rendered_html']);
        $this->assertStringContainsString('Giường: G01', $dorm['rendered_html']);
        foreach (['contracts', 'contract_documents', 'room_tenants', 'invoices', 'payments', 'rooms', 'beds', 'tenants', 'landlords'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
