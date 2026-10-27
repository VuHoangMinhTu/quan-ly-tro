<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractDocument;
use App\Models\ContractTemplate;
use App\Models\User;
use App\Services\ContractTemplateRenderer;
use Database\Seeders\RestoreDefaultContractTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class RestoreDefaultContractTemplatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_defaults_are_restored_from_verified_source_and_repeated_runs_change_nothing(): void
    {
        DB::table('contract_templates')->where('scope_key', 'GLOBAL')->delete();
        User::factory()->create();
        $users = DB::table('users')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        $this->seed(RestoreDefaultContractTemplatesSeeder::class);

        $this->assertDatabaseCount('contract_templates', 2);
        foreach (['ROOM_RENTAL_STANDARD' => 'contracts-room-rental-word.html', 'DORM_BED_STANDARD' => 'contracts-dorm-bed-word.html'] as $key => $file) {
            $template = ContractTemplate::query()->where('scope_key', 'GLOBAL')->where('template_key', $key)->sole();
            $this->assertTrue($template->is_active);
            $this->assertNull($template->landlord_id);
            $this->assertSame(app(ContractTemplateRenderer::class)->prepare(file_get_contents(resource_path('views/'.$file))), $template->content_html);
        }
        $before = DB::table('contract_templates')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $this->seed(RestoreDefaultContractTemplatesSeeder::class);
        $this->assertSame($before, DB::table('contract_templates')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame($users, DB::table('users')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_existing_global_versions_and_inactive_choices_are_not_rewritten_or_reactivated(): void
    {
        $template = ContractTemplate::query()->where('scope_key', 'GLOBAL')->where('template_key', 'ROOM_RENTAL_STANDARD')->latest('version')->firstOrFail();
        $template->update(['content_html' => '<p>Nội dung kiểm thử cần giữ nguyên.</p>', 'is_active' => false, 'name' => 'Tên đã lưu']);
        $before = DB::table('contract_templates')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        $this->seed(RestoreDefaultContractTemplatesSeeder::class);

        $this->assertSame($before, DB::table('contract_templates')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
    }

    public function test_partial_restore_preserves_custom_templates_and_finalized_document_snapshots(): void
    {
        DB::table('contract_templates')->where('scope_key', 'GLOBAL')->where('template_key', 'DORM_BED_STANDARD')->delete();
        $contract = Contract::factory()->create();
        $custom = ContractTemplate::factory()->create(['landlord_id' => $contract->landlord_id, 'scope_key' => 'LANDLORD:'.$contract->landlord_id, 'template_key' => 'ROOM_RENTAL_STANDARD', 'is_active' => false]);
        $room = ContractTemplate::query()->where('scope_key', 'GLOBAL')->where('template_key', 'ROOM_RENTAL_STANDARD')->firstOrFail();
        $document = ContractDocument::factory()->create(['contract_id' => $contract->id, 'contract_template_id' => $room->id]);
        $customBefore = $custom->fresh()->getAttributes();
        $documentBefore = $document->fresh()->getAttributes();
        $contractBefore = $document->contract->fresh()->getAttributes();

        $this->seed(RestoreDefaultContractTemplatesSeeder::class);

        $this->assertDatabaseHas('contract_templates', ['scope_key' => 'GLOBAL', 'template_key' => 'DORM_BED_STANDARD', 'is_active' => true]);
        $this->assertSame($customBefore, $custom->fresh()->getAttributes());
        $this->assertSame($documentBefore, $document->fresh()->getAttributes());
        $this->assertSame($contractBefore, $document->contract->fresh()->getAttributes());
    }

    public function test_dangling_template_reference_stops_recovery_instead_of_rebinding_old_contract_ids(): void
    {
        $template = ContractTemplate::query()->where('scope_key', 'GLOBAL')->firstOrFail();
        $contract = Contract::factory()->create(['contract_template_id' => $template->id]);
        // Defer constraints only inside this SQLite test transaction to simulate accidental production deletion.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('contract_templates')->where('id', $template->id)->delete();
        $before = DB::table('contract_templates')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        try {
            $this->seed(RestoreDefaultContractTemplatesSeeder::class);
            $this->fail('Recovery must stop when old contract template references are missing.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('tham chiếu ID mẫu đã mất', $error->getMessage());
        }

        $this->assertSame($before, DB::table('contract_templates')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame($template->id, $contract->fresh()->contract_template_id);
    }
}
