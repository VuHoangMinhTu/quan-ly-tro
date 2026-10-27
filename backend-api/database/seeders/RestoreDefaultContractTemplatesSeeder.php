<?php

namespace Database\Seeders;

use App\Models\ContractTemplate;
use App\Services\ContractTemplateRenderer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class RestoreDefaultContractTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('contract_templates')) {
            throw new RuntimeException('Thiếu bảng contract_templates. Hãy khôi phục cấu trúc database trước; seeder không tự tạo hoặc reset bảng.');
        }
        $sources = [
            'ROOM_RENTAL_STANDARD' => ['file' => 'contracts-room-rental-word.html', 'name' => 'Hợp đồng cho thuê phòng tiêu chuẩn'],
            'DORM_BED_STANDARD' => ['file' => 'contracts-dorm-bed-word.html', 'name' => 'Hợp đồng cho thuê giường ký túc xá'],
        ];
        $defaults = [];
        foreach ($sources as $key => $source) {
            $path = resource_path('views/'.$source['file']);
            if (! is_readable($path)) {
                throw new RuntimeException('Không tìm thấy nguồn mẫu đã xác minh: '.$source['file']);
            }
            $content = file_get_contents($path);
            if ($content === false) {
                throw new RuntimeException('Không đọc được nguồn mẫu đã xác minh: '.$source['file']);
            }
            $defaults[$key] = ['name' => $source['name'], 'content_html' => app(ContractTemplateRenderer::class)->prepare($content)];
        }

        DB::transaction(function () use ($defaults): void {
            foreach (['contracts', 'contract_documents'] as $table) {
                if (Schema::hasTable($table) && DB::table($table)->whereNotNull('contract_template_id')
                    ->whereNotIn('contract_template_id', ContractTemplate::query()->select('id'))->exists()) {
                    throw new RuntimeException('Có hợp đồng/văn bản tham chiếu ID mẫu đã mất. Hãy đối soát backup trước; seeder dừng để tránh gán nhầm mẫu cho chứng từ cũ.');
                }
            }
            foreach ($defaults as $key => $data) {
                // Preserve every existing version, content, status and reference, including intentionally inactive defaults.
                $template = ContractTemplate::query()->firstOrCreate(['scope_key' => 'GLOBAL', 'template_key' => $key], [
                    ...$data, 'landlord_id' => null, 'version' => 1, 'is_active' => true,
                ]);
                $this->command?->info($key.($template->wasRecentlyCreated ? ': đã khôi phục mẫu mặc định từ source.' : ': đã tồn tại, giữ nguyên dữ liệu.'));
            }
        }, 3);
    }
}
