<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractDocument;
use App\Models\ContractTemplate;
use App\Models\User;
use App\Services\ContractTemplateRenderer;
use App\Services\ContractTemplateVariableRegistry;
use Database\Seeders\StandardContractTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ContractDocumentsApiTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $contract = Contract::factory()->create(['start_date' => '2026-11-01', 'end_date' => '2027-10-31', 'contract_code' => 'HD-101', 'monthly_rent' => 3800000, 'deposit_amount' => 7600000]);
        $contract->tenant->update(['full_name' => 'Nguyễn Văn A', 'phone' => '0900000000', 'identity_number' => '012345678901', 'date_of_birth' => '2003-09-28']);
        $contract->room->update(['room_code' => '101', 'area' => 20.5]);
        $owner = $contract->room->boardingHouse->landlord->user;
        $owner->update(['name' => 'Đại diện Bên A']);
        $this->actingAs($owner, 'sanctum');

        return [$contract, $owner];
    }

    private function template(string $html = '<h1>Hợp đồng {{contract.code}}</h1><p>{{tenant.full_name}} — {{tenant.phone}} — {{contract.start_date}} — {{contract.monthly_rent}} — {{contract.deposit}}</p>{{services_table}}{{occupants_table}}'): int
    {
        return $this->postJson('/api/contract-templates', [
            'template_key' => 'ROOM_RENTAL_STANDARD', 'name' => 'Hợp đồng riêng', 'content_html' => $html, 'is_active' => true,
            'landlord_id' => 99999, 'version' => 999,
        ])->assertCreated()->json('data.id');
    }

    private function prepare(Contract $contract, User $owner, ?int $templateId = null): void
    {
        $this->putJson("/api/contracts/{$contract->id}", [
            'lessor_representative_user_id' => $owner->id, 'contract_template_id' => $templateId,
        ])->assertOk();
        $contract->handoverReports()->create(['type' => 'CHECK_IN', 'status' => 'CONFIRMED', 'items' => [['name' => 'Giường', 'quantity' => 1, 'condition' => 'GOOD', 'note' => null]], 'confirmed_at' => now()]);
    }

    public function test_beehouse_standard_template_is_active_global_valid_and_seeded_once(): void
    {
        $template = ContractTemplate::query()->whereNull('landlord_id')->where('template_key', 'ROOM_RENTAL_STANDARD')->sole();

        $this->assertSame(1, $template->version);
        $this->assertTrue($template->is_active);
        $this->assertSame('Hợp đồng cho thuê phòng tiêu chuẩn', $template->name);
        $this->assertSame($template->content_html, app(ContractTemplateRenderer::class)->prepare($template->content_html));
        $this->assertStringContainsString('{{vehicles_table}}', $template->content_html);
        $this->assertStringContainsString('<div class="document-signatures"></div>', $template->content_html);
        app(StandardContractTemplateSeeder::class)->run();
        $this->assertSame(1, ContractTemplate::query()->whereNull('landlord_id')->where('template_key', 'ROOM_RENTAL_STANDARD')->count());
    }

    public function test_reseeding_changed_global_template_creates_new_version_without_mutating_old_content(): void
    {
        $original = ContractTemplate::query()->whereNull('landlord_id')->where('template_key', 'ROOM_RENTAL_STANDARD')->sole();
        $original->update(['content_html' => '<p>Đã tùy chỉnh trước khi seed mới.</p>']);

        app(StandardContractTemplateSeeder::class)->run();
        app(StandardContractTemplateSeeder::class)->run();

        $this->assertSame('<p>Đã tùy chỉnh trước khi seed mới.</p>', $original->fresh()->content_html);
        $this->assertFalse($original->fresh()->is_active);
        $this->assertDatabaseHas('contract_templates', ['scope_key' => 'GLOBAL', 'template_key' => 'ROOM_RENTAL_STANDARD', 'version' => 2, 'is_active' => true]);
        $this->assertSame(2, ContractTemplate::query()->whereNull('landlord_id')->where('template_key', 'ROOM_RENTAL_STANDARD')->count());
    }

    public function test_standard_preview_formats_optional_values_and_empty_collection_blocks(): void
    {
        [$contract] = $this->fixture();

        $html = $this->getJson("/api/contracts/{$contract->id}/document/preview")->assertOk()->json('data.rendered_html');

        $this->assertStringContainsString('3.800.000 đồng', $html);
        $this->assertStringContainsString('01/11/2026', $html);
        $this->assertStringContainsString('Chưa cập nhật', $html);
        $this->assertStringContainsString('Không có phương tiện được đăng ký.', $html);
        $this->assertStringContainsString('Chưa có biên bản bàn giao được ghi nhận.', $html);
        $this->assertSame(1, substr_count($html, 'class="document-signatures"'));
        $this->assertStringNotContainsString('undefined', $html);
        $this->assertStringNotContainsString('[object Object]', $html);
    }

    public function test_vehicle_and_handover_blocks_render_supplied_snapshot_rows_without_html_injection(): void
    {
        $snapshot = app(ContractTemplateVariableRegistry::class)->sample();
        $snapshot['vehicles'] = [['type' => 'Xe máy', 'license_plate' => '59-X1 12345', 'note' => '<script>bad</script>']];
        $snapshot['handover_checkin'] = [['name' => 'Máy lạnh', 'quantity' => 1, 'condition' => 'Hoạt động']];

        $html = app(ContractTemplateRenderer::class)->render('{{vehicles_table}}{{handover_checkin_table}}', $snapshot);

        $this->assertStringContainsString('59-X1 12345', $html);
        $this->assertStringContainsString('Máy lạnh', $html);
        $this->assertStringContainsString('Hoạt động', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_document_and_template_endpoints_require_sanctum(): void
    {
        $this->getJson('/api/contract-templates')->assertUnauthorized();
        $this->postJson('/api/contract-templates', [])->assertUnauthorized();
        $this->postJson('/api/contract-templates/preview', [])->assertUnauthorized();
        $this->getJson('/api/contracts/1/document/preview')->assertUnauthorized();
        $this->getJson('/api/contracts/1/document')->assertUnauthorized();
        $this->postJson('/api/contracts/1/finalize')->assertUnauthorized();
    }

    public function test_owner_creates_private_version_and_cannot_mutate_global_default(): void
    {
        [$contract, $owner] = $this->fixture();
        $id = $this->template();

        $this->assertDatabaseHas('contract_templates', ['id' => $id, 'landlord_id' => $contract->room->boardingHouse->landlord_id, 'created_by_user_id' => $owner->id, 'version' => 1]);
        $global = ContractTemplate::whereNull('landlord_id')->where('template_key', 'ROOM_RENTAL_STANDARD')->firstOrFail();
        $this->putJson("/api/contract-templates/{$global->id}", ['content_html' => '<p>Không được sửa</p>'])->assertNotFound();
        $this->putJson("/api/contract-templates/{$global->id}/status", ['is_active' => false])->assertNotFound();
        $this->getJson('/api/contract-templates')->assertOk()->assertJsonPath('data.can_manage', true);
        $cloneId = $this->postJson("/api/contract-templates/{$global->id}/versions", [])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('contract_templates', ['id' => $cloneId, 'template_key' => $global->template_key, 'landlord_id' => $contract->room->boardingHouse->landlord_id, 'name' => 'Hợp đồng cho thuê phòng tiêu chuẩn - Bản tùy chỉnh', 'version' => 2]);
        $this->assertSame($global->content_html, ContractTemplate::findOrFail($cloneId)->content_html);
    }

    public function test_first_clone_of_standard_template_starts_at_version_one(): void
    {
        [$contract] = $this->fixture();
        $global = ContractTemplate::query()->whereNull('landlord_id')->where('template_key', 'ROOM_RENTAL_STANDARD')->sole();

        $id = $this->postJson("/api/contract-templates/{$global->id}/versions", [])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('contract_templates', ['id' => $id, 'landlord_id' => $contract->room->boardingHouse->landlord_id, 'template_key' => 'ROOM_RENTAL_STANDARD', 'version' => 1]);
    }

    public function test_manager_cannot_manage_legal_templates_but_can_preview_and_finalize_assigned_contract(): void
    {
        [$contract, $owner] = $this->fixture();
        $templateId = $this->template();
        $this->prepare($contract, $owner, $templateId);
        $manager = User::factory()->create();
        $contract->room->boardingHouse->managers()->attach($manager);
        $this->actingAs($manager, 'sanctum');

        $this->postJson('/api/contract-templates', ['name' => 'Sai'])->assertForbidden();
        $this->putJson("/api/contract-templates/{$templateId}", ['name' => 'Sai'])->assertForbidden();
        $this->postJson("/api/contract-templates/{$templateId}/versions", [])->assertForbidden();
        $this->putJson("/api/contract-templates/{$templateId}/status", ['is_active' => false])->assertForbidden();
        $this->getJson("/api/contracts/{$contract->id}/document/preview")->assertOk();
        $this->postJson("/api/contracts/{$contract->id}/finalize")->assertOk();
        $this->getJson("/api/contracts/{$contract->id}/document")->assertOk();
        $contract->room->boardingHouse->managers()->detach($manager);
        $this->getJson("/api/contracts/{$contract->id}/document")->assertNotFound();
    }

    public function test_foreign_templates_and_contracts_are_not_accessible_or_selectable(): void
    {
        [$contract, $owner] = $this->fixture();
        $templateId = $this->template();
        $foreign = Contract::factory()->create();
        $other = $foreign->room->boardingHouse->landlord->user;
        $this->actingAs($other, 'sanctum');

        $this->getJson("/api/contract-templates/{$templateId}")->assertNotFound();
        $this->putJson("/api/contracts/{$foreign->id}", ['contract_template_id' => $templateId])->assertNotFound();
        $this->postJson("/api/contract-templates/{$templateId}/versions", [])->assertNotFound();
        $this->getJson("/api/contracts/{$contract->id}/document/preview")->assertNotFound();
        $this->postJson("/api/contracts/{$contract->id}/finalize")->assertNotFound();
        $this->assertDatabaseHas('contracts', ['id' => $foreign->id, 'contract_template_id' => null]);
    }

    public function test_unknown_and_malformed_placeholders_are_rejected_with_specific_feedback(): void
    {
        $this->fixture();
        $this->postJson('/api/contract-templates/preview', ['content_html' => '<p>{{tenant.fulln_ame}}</p>'])
            ->assertUnprocessable()->assertJsonPath('errors.content_html.0', 'Biến {{tenant.fulln_ame}} không được hỗ trợ.');
        $this->postJson('/api/contract-templates/preview', ['content_html' => '<p>{{tenant.full_name</p>'])->assertUnprocessable();
        $this->postJson('/api/contract-templates/preview', ['content_html' => '<p>{{unknown_table}}</p>'])->assertUnprocessable();
    }

    #[TestWith(['<script>alert(1)</script>'])]
    #[TestWith(['<p onclick="alert(1)">Hello</p>'])]
    #[TestWith(['<iframe src="https://example.com"></iframe>'])]
    #[TestWith(['<a href="javascript:alert(1)">Click</a>'])]
    #[TestWith(['<svg onload="alert(1)"></svg>'])]
    #[TestWith(['<p style="position:fixed">Fake overlay</p>'])]
    #[TestWith(['<?php system("secret"); ?>'])]
    #[TestWith(['<!DOCTYPE html [<!ENTITY x SYSTEM "file:///etc/passwd">]><p>&x;</p>'])]
    #[TestWith(['</body><head><script>alert(1)</script></head>'])]
    public function test_dangerous_html_is_rejected_without_persistence(string $html): void
    {
        $this->fixture();

        $this->postJson('/api/contract-templates', ['template_key' => 'UNSAFE', 'name' => 'Unsafe', 'content_html' => $html])
            ->assertUnprocessable()->assertJsonValidationErrors('content_html');

        $this->assertDatabaseMissing('contract_templates', ['template_key' => 'UNSAFE']);
    }

    public function test_renderer_escapes_scalar_and_collection_values_and_formats_business_dates_without_timezone_conversion(): void
    {
        [$contract, $owner] = $this->fixture();
        $contract->tenant->update(['full_name' => '<script>alert(1)</script>']);
        $id = $this->template('<p>{{tenant.full_name}} {{tenant.date_of_birth}} {{room.area}} {{contract.monthly_rent}}</p>{{occupants_table}}');
        $this->prepare($contract, $owner, $id);

        $html = $this->getJson("/api/contracts/{$contract->id}/document/preview")->assertOk()->json('data.rendered_html');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('28/09/2003', $html);
        $this->assertStringContainsString('20,5 m²', $html);
        $this->assertStringContainsString('3.800.000 đồng', $html);
        $this->assertDatabaseCount('contract_documents', 0);
    }

    public function test_finalize_uses_exact_snapshot_is_idempotent_and_never_changes_with_live_data_or_new_template(): void
    {
        [$contract, $owner] = $this->fixture();
        $id = $this->template();
        $this->prepare($contract, $owner, $id);
        $this->postJson("/api/contracts/{$contract->id}/finalize")->assertOk()->assertJsonPath('data', null);
        $document = $contract->document()->sole();
        $html = $document->rendered_html;

        $this->assertStringContainsString('Nguyễn Văn A', $html);
        $this->assertStringContainsString('01/11/2026', $html);
        $this->assertStringContainsString('3.800.000 đồng', $html);
        $this->assertStringContainsString('7.600.000 đồng', $html);
        $this->assertSame($contract->fresh()->signing_snapshot, $document->source_snapshot);
        $this->assertSame(hash('sha256', $html), $document->document_hash);
        $this->assertNotSame(hash('sha256', $html.'x'), $document->document_hash);
        $this->putJson("/api/contract-templates/{$id}", ['content_html' => '<p>Đổi nội dung</p>'])->assertUnprocessable()->assertJsonPath('errors.content_html.0', 'Mẫu này đã được sử dụng. Hãy tạo phiên bản mới để chỉnh sửa.');
        $contract->tenant->update(['full_name' => 'Tên mới', 'phone' => '0999999999']);
        $contract->room->update(['monthly_rent' => 9999999]);
        $owner->update(['name' => 'Tên đại diện mới']);
        $this->postJson("/api/contract-templates/{$id}/versions", ['content_html' => '<p>Nội dung mới</p>', 'is_active' => true])->assertCreated();
        $this->postJson("/api/contracts/{$contract->id}/finalize")->assertOk();
        $this->putJson("/api/contracts/{$contract->id}/status", ['status' => 'ACTIVE'])->assertOk();

        $this->assertDatabaseCount('contract_documents', 1);
        $this->getJson("/api/contracts/{$contract->id}/document")->assertOk()->assertJsonPath('data.rendered_html', $html)->assertJsonPath('data.template_version', 1);
        $this->getJson("/api/contracts/{$contract->id}/document/preview")->assertJsonPath('data.rendered_html', $html)->assertJsonPath('data.is_preview', false);
        $this->assertDatabaseHas('contract_templates', ['id' => $id, 'version' => 1, 'is_active' => false]);
        $this->assertDatabaseHas('contract_templates', ['template_key' => 'ROOM_RENTAL_STANDARD', 'version' => 2, 'is_active' => true]);
    }

    public function test_template_selection_does_not_change_when_new_version_activates_and_disabled_choice_requires_explicit_reselection(): void
    {
        [$contract, $owner] = $this->fixture();
        $id = $this->template();
        $this->prepare($contract, $owner, $id);
        $v2 = $this->postJson("/api/contract-templates/{$id}/versions", ['is_active' => true])->assertCreated()->json('data.id');

        $this->postJson("/api/contracts/{$contract->id}/finalize")->assertUnprocessable()->assertJsonValidationErrors('contract_template_id');
        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'status' => 'DRAFT', 'contract_template_id' => $id, 'signing_snapshot' => null]);
        $this->putJson("/api/contracts/{$contract->id}", ['contract_template_id' => $v2])->assertOk();
        $this->postJson("/api/contracts/{$contract->id}/finalize")->assertOk();
        $this->assertDatabaseHas('contract_documents', ['contract_id' => $contract->id, 'contract_template_id' => $v2, 'template_version' => 2]);
    }

    public function test_render_failure_rolls_back_activation_snapshots_and_occupancy(): void
    {
        [$contract, $owner] = $this->fixture();
        $this->prepare($contract, $owner, $this->template());
        $this->mock(ContractTemplateRenderer::class)->shouldReceive('render')->once()->andThrow(ValidationException::withMessages(['content_html' => 'Không thể render mẫu.']));

        $this->postJson("/api/contracts/{$contract->id}/finalize")->assertUnprocessable();

        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'status' => 'DRAFT', 'signing_snapshot' => null, 'finalized_at' => null]);
        $this->assertDatabaseCount('room_tenants', 0);
        $this->assertDatabaseCount('contract_documents', 0);
    }

    public function test_document_insert_failure_rolls_back_activation_too(): void
    {
        [$contract, $owner] = $this->fixture();
        $this->prepare($contract, $owner, $this->template());
        $event = 'eloquent.creating: '.ContractDocument::class;
        Event::listen($event, fn () => throw ValidationException::withMessages(['document' => 'Không lưu được tài liệu.']));
        try {
            $this->postJson("/api/contracts/{$contract->id}/finalize")->assertUnprocessable();
        } finally {
            Event::forget($event);
        }

        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'status' => 'DRAFT', 'signing_snapshot' => null]);
        $this->assertDatabaseCount('contract_documents', 0);
        $this->assertDatabaseCount('room_tenants', 0);
    }

    public function test_default_template_is_available_without_custom_content_and_legacy_active_is_not_fabricated(): void
    {
        [$contract, $owner] = $this->fixture();
        $this->getJson("/api/rooms/{$contract->room_id}/contract-context")->assertOk()->assertJsonPath('data.templates.0.template_key', 'ROOM_RENTAL_STANDARD');
        $contract->update(['status' => 'ACTIVE']);
        $this->getJson("/api/contracts/{$contract->id}")->assertOk()->assertJsonPath('data.document', null);
        $this->getJson("/api/contracts/{$contract->id}/document")->assertNotFound();
        $this->postJson("/api/contracts/{$contract->id}/finalize")->assertUnprocessable();
        $this->assertDatabaseCount('contract_documents', 0);
    }

    public function test_normal_active_transition_also_captures_one_document(): void
    {
        [$contract, $owner] = $this->fixture();
        $this->prepare($contract, $owner);

        $this->putJson("/api/contracts/{$contract->id}/status", ['status' => 'ACTIVE'])->assertOk();

        $this->assertDatabaseCount('contract_documents', 1);
        $this->assertStringContainsString('HỢP ĐỒNG CHO THUÊ PHÒNG', $contract->document()->sole()->rendered_html);
        $this->putJson("/api/contracts/{$contract->id}", ['contract_template_id' => 9999])->assertUnprocessable();
    }

    public function test_services_and_occupants_blocks_use_the_finalization_snapshot_not_future_live_prices(): void
    {
        [$contract, $owner] = $this->fixture();
        $service = $contract->room->boardingHouse->services()->create(['name' => 'Internet đã thỏa thuận', 'type' => 'INTERNET', 'billing_method' => 'FIXED', 'base_price' => 150000, 'vat_rate' => 8, 'is_active' => true]);
        $contract->room->services()->attach($service, ['is_active' => true]);
        $this->prepare($contract, $owner, $this->template());
        $this->putJson("/api/contracts/{$contract->id}", ['services' => [['service_id' => $service->id]]])->assertOk();
        $this->postJson("/api/contracts/{$contract->id}/finalize")->assertOk();
        $html = $contract->document()->sole()->rendered_html;
        $service->update(['name' => 'Giá mới', 'base_price' => 999999, 'vat_rate' => 20]);

        $this->getJson("/api/contracts/{$contract->id}/document")->assertJsonPath('data.rendered_html', $html);

        $this->assertStringContainsString('Internet đã thỏa thuận', $html);
        $this->assertStringContainsString('150.000 đồng', $html);
        $this->assertStringContainsString('8%', $html);
        $this->assertStringContainsString('Người đứng tên', $html);
    }

    public function test_finalized_model_refuses_in_place_content_mutation(): void
    {
        [$contract, $owner] = $this->fixture();
        $this->prepare($contract, $owner);
        $this->postJson("/api/contracts/{$contract->id}/finalize")->assertOk();

        $this->expectException(\LogicException::class);
        $contract->document()->sole()->update(['rendered_html' => '<p>Changed</p>']);
    }

    public function test_global_template_usage_count_never_leaks_other_landlord_documents(): void
    {
        [$contract, $owner] = $this->fixture();
        $this->prepare($contract, $owner);
        $this->postJson("/api/contracts/{$contract->id}/finalize")->assertOk();
        $global = ContractTemplate::whereNull('landlord_id')->firstOrFail();
        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum');

        $this->getJson("/api/contract-templates/{$global->id}")->assertOk()->assertJsonPath('data.documents_count', 0);
        $this->getJson('/api/contract-templates')->assertOk()->assertJsonPath('data.templates.0.documents_count', 0);
    }
}
