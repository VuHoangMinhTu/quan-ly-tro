<?php

namespace Database\Seeders;

use App\Models\ContractTemplate;
use App\Services\ContractTemplateRenderer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DormBedContractTemplateSeeder extends Seeder
{
    public function run(): void
    {
        // Transcribed in source order from "Chính Hợp Đồng KTX.docx".
        $source = file_get_contents(resource_path('views/contracts-dorm-bed-word.html'));
        if ($source === false) {
            throw new RuntimeException('Không tìm thấy nội dung mẫu hợp đồng ký túc xá.');
        }
        $content = app(ContractTemplateRenderer::class)->prepare($source);

        DB::transaction(function () use ($content): void {
            $versions = ContractTemplate::query()->where('scope_key', 'GLOBAL')->where('template_key', 'DORM_BED_STANDARD')
                ->lockForUpdate()->orderByDesc('version')->get();
            $existing = $versions->firstWhere('content_html', $content);
            if ($existing) {
                if (! $existing->is_active) {
                    ContractTemplate::query()->whereKey($versions->pluck('id'))->update(['is_active' => false]);
                    $existing->update(['is_active' => true]);
                }

                return;
            }
            ContractTemplate::query()->whereKey($versions->pluck('id'))->update(['is_active' => false]);
            ContractTemplate::create([
                'scope_key' => 'GLOBAL', 'landlord_id' => null, 'template_key' => 'DORM_BED_STANDARD',
                'name' => 'Hợp đồng cho thuê giường ký túc xá',
                'version' => ($versions->first()?->version ?? 0) + 1, 'is_active' => true,
                'content_html' => $content,
            ]);
        }, 3);
    }
}
