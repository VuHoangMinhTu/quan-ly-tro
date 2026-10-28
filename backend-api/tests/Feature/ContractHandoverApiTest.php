<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\Contract;
use App\Models\ContractDocument;
use App\Models\ContractHandoverReport;
use App\Models\ContractTemplate;
use App\Services\ContractHandoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractHandoverApiTest extends TestCase
{
    use RefreshDatabase;

    private function contract(): Contract
    {
        $contract = Contract::factory()->create();
        $this->actingAs($contract->room->boardingHouse->landlord->user, 'sanctum');

        return $contract;
    }

    public function test_create_prefills_defaults_and_room_amenities_without_duplicates(): void
    {
        $contract = $this->contract();
        $contract->room->amenities()->attach([Amenity::create(['name' => 'Máy lạnh'])->id, Amenity::create(['name' => 'Tivi'])->id]);

        $this->postJson("/api/contracts/{$contract->id}/handover-check-in")->assertCreated()->assertJsonPath('data', null);
        $items = $this->getJson("/api/contracts/{$contract->id}/handover-check-in")->assertOk()->json('data.items');

        $this->assertSame('DRAFT', ContractHandoverReport::query()->sole()->status);
        $this->assertCount(13, $items);
        $this->assertSame('Phòng mới không có lỗ đinh, Tường trắng sạch sẽ', $items[0]['name']);
        $this->assertSame('Tủ, kệ bếp', $items[12]['name']);
        $this->assertNull($items[0]['condition']);
        $this->assertCount(1, array_filter($items, fn (array $item) => $item['name'] === 'Máy lạnh'));
        $this->assertContains('Tivi', array_column($items, 'name'));
        $this->postJson("/api/contracts/{$contract->id}/handover-check-in")->assertUnprocessable();
    }

    public function test_dorm_creation_prefills_the_six_word_items_in_order(): void
    {
        $contract = $this->contract();
        $contract->update(['contract_type' => 'DORM_BED']);

        $this->postJson("/api/contracts/{$contract->id}/handover-check-in")->assertCreated();
        $items = $this->getJson("/api/contracts/{$contract->id}/handover-check-in")->assertOk()->json('data.items');

        $this->assertSame(ContractHandoverService::DORM_BED_ITEMS, array_column($items, 'name'));
        $this->assertSame(array_fill(0, 6, null), array_column($items, 'condition'));
    }

    public function test_edit_confirm_and_preview_only_include_confirmed_check_in(): void
    {
        $contract = $this->contract();
        $this->postJson("/api/contracts/{$contract->id}/handover-check-in")->assertCreated();
        $items = [['name' => 'Tivi', 'quantity' => 2, 'condition' => 'DAMAGED', 'note' => 'Trầy nhẹ']];
        $this->putJson("/api/contracts/{$contract->id}/handover-check-in", ['items' => $items])->assertOk();
        $draft = $this->getJson("/api/contracts/{$contract->id}/document/preview")->assertOk()->json('data.rendered_html');
        $this->assertSame(1, substr_count($draft, 'class="contract-handover-table"'));
        $this->assertStringContainsString('<td>Tivi</td><td>Mới, tốt ☐ &nbsp; Cũ, hư ☐', $draft);

        $this->postJson("/api/contracts/{$contract->id}/handover-check-in/confirm")->assertOk();
        $confirmed = $this->getJson("/api/contracts/{$contract->id}/document/preview")->assertOk()->json('data.rendered_html');

        $this->assertStringContainsString('<td>Tivi</td>', $confirmed);
        $this->assertStringContainsString('<td>Tivi</td><td>Mới, tốt ☐ &nbsp; Cũ, hư ☑<br>Trầy nhẹ', $confirmed);
        $this->assertDatabaseHas('contract_handover_reports', ['contract_id' => $contract->id, 'type' => 'CHECK_IN', 'status' => 'CONFIRMED']);
        $this->putJson("/api/contracts/{$contract->id}/handover-check-in", ['items' => $items])->assertUnprocessable();
        $this->postJson("/api/contracts/{$contract->id}/handover-check-in/confirm")->assertUnprocessable();
    }

    public function test_foreign_contract_is_hidden_and_items_are_validated(): void
    {
        $contract = $this->contract();
        $this->postJson("/api/contracts/{$contract->id}/handover-check-in")->assertCreated();
        $this->putJson("/api/contracts/{$contract->id}/handover-check-in", ['items' => [['name' => '', 'quantity' => -1, 'condition' => 'BAD']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['items.0.name', 'items.0.quantity', 'items.0.condition']);
        $other = Contract::factory()->create();
        $this->actingAs($other->room->boardingHouse->landlord->user, 'sanctum');

        $this->getJson("/api/contracts/{$contract->id}/handover-check-in")->assertNotFound();
        $this->postJson("/api/contracts/{$contract->id}/handover-check-in")->assertNotFound();
        $this->putJson("/api/contracts/{$contract->id}/handover-check-in", ['items' => [['name' => 'Tivi', 'quantity' => 1, 'condition' => 'GOOD']]])->assertNotFound();
        $this->postJson("/api/contracts/{$contract->id}/handover-check-in/confirm")->assertNotFound();
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->getJson('/api/contracts/1/handover-check-in')->assertUnauthorized();
        $this->postJson('/api/contracts/1/handover-check-in')->assertUnauthorized();
        $this->putJson('/api/contracts/1/handover-check-in', ['items' => []])->assertUnauthorized();
        $this->postJson('/api/contracts/1/handover-check-in/confirm')->assertUnauthorized();
    }

    public function test_confirmed_check_in_is_snapshotted_when_document_is_finalized(): void
    {
        $contract = $this->contract();
        $owner = $contract->room->boardingHouse->landlord->user;
        $this->putJson("/api/contracts/{$contract->id}", ['lessor_representative_user_id' => $owner->id])->assertOk();
        $this->postJson("/api/contracts/{$contract->id}/handover-check-in")->assertCreated();
        $this->putJson("/api/contracts/{$contract->id}/handover-check-in", ['items' => [['name' => 'Tivi mới', 'quantity' => 1, 'condition' => 'GOOD']]])->assertOk();
        $this->postJson("/api/contracts/{$contract->id}/handover-check-in/confirm")->assertOk();

        $this->postJson("/api/contracts/{$contract->id}/finalize")->assertOk();

        $document = $contract->document()->sole();
        $this->assertStringContainsString('Tivi mới', $document->rendered_html);
        $this->assertSame('Tivi mới', $document->source_snapshot['handover_checkin'][0]['name']);
        $this->assertStringNotContainsString('<td>Tivi</td>', $document->rendered_html);
        $this->assertStringNotContainsString('Ballet, nệm.', $document->rendered_html);
        $this->assertSame(['Tivi mới'], $document->source_snapshot['handover_item_names']);
    }

    public function test_removed_default_items_stay_removed_after_reload_preview_and_confirmation(): void
    {
        $contract = $this->contract();
        $this->postJson("/api/contracts/{$contract->id}/handover-check-in")->assertCreated();
        $items = [['name' => 'Khóa cửa', 'quantity' => 1, 'key_count' => 2, 'condition' => 'GOOD']];
        $this->putJson("/api/contracts/{$contract->id}/handover-check-in", ['items' => $items])->assertOk();
        $this->getJson("/api/contracts/{$contract->id}/handover-check-in")->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.name', 'Khóa cửa');
        foreach ([false, true] as $confirmed) {
            if ($confirmed) {
                $this->postJson("/api/contracts/{$contract->id}/handover-check-in/confirm")->assertOk();
            }
            $html = $this->getJson("/api/contracts/{$contract->id}/document/preview")->assertOk()->json('data.rendered_html');
            $this->assertStringContainsString('Số lượng chìa:', $html);
            $this->assertStringNotContainsString('Ballet, nệm.', $html);
            $this->assertStringNotContainsString('<td>Tivi</td>', $html);
            $this->assertSame(2, substr_count($html, '<tr>'));
        }
        $this->putJson("/api/contracts/{$contract->id}/handover-check-in", ['items' => []])->assertUnprocessable();
    }

    public function test_all_items_can_be_removed_without_reintroducing_defaults_into_final_document(): void
    {
        $contract = $this->contract();
        $owner = $contract->room->boardingHouse->landlord->user;
        $this->putJson("/api/contracts/{$contract->id}", ['lessor_representative_user_id' => $owner->id])->assertOk();
        $this->postJson("/api/contracts/{$contract->id}/handover-check-in")->assertCreated();
        $this->putJson("/api/contracts/{$contract->id}/handover-check-in", ['items' => []])->assertOk();
        $this->getJson("/api/contracts/{$contract->id}/handover-check-in")->assertOk()->assertJsonPath('data.items', []);
        $this->postJson("/api/contracts/{$contract->id}/handover-check-in/confirm")->assertOk();
        $this->postJson("/api/contracts/{$contract->id}/finalize")->assertOk();
        $document = $contract->document()->sole();
        $this->assertStringContainsString('Không có hạng mục bàn giao.', $document->rendered_html);
        $this->assertStringNotContainsString('Ballet, nệm.', $document->rendered_html);
        $this->assertSame([], $document->source_snapshot['handover_checkin']);
    }

    public function test_dorm_deleted_items_do_not_reappear_and_remaining_units_follow_item_names(): void
    {
        $contract = $this->contract();
        $contract->update(['contract_type' => 'DORM_BED', 'contract_template_id' => ContractTemplate::query()->where('template_key', 'DORM_BED_STANDARD')->where('is_active', true)->firstOrFail()->id]);
        $this->postJson("/api/contracts/{$contract->id}/handover-check-in")->assertCreated();
        $name = ContractHandoverService::DORM_BED_ITEMS[2];
        $this->putJson("/api/contracts/{$contract->id}/handover-check-in", ['items' => [['name' => $name, 'quantity' => 1, 'condition' => 'GOOD']]])->assertOk();
        $this->postJson("/api/contracts/{$contract->id}/handover-check-in/confirm")->assertOk();
        $html = $this->getJson("/api/contracts/{$contract->id}/document/preview")->assertOk()->json('data.rendered_html');
        $this->assertStringContainsString('01 tủ', $html);
        $this->assertStringNotContainsString(ContractHandoverService::DORM_BED_ITEMS[0], $html);
        $this->assertSame(2, substr_count($html, '<tr>'));
    }

    public function test_deletion_is_rejected_after_contract_finalization_even_if_report_is_still_draft(): void
    {
        $contract = $this->contract();
        $this->postJson("/api/contracts/{$contract->id}/handover-check-in")->assertCreated();
        $before = ContractHandoverReport::query()->sole()->items;
        $contract->update(['status' => 'ACTIVE', 'finalized_at' => now()]);
        $this->putJson("/api/contracts/{$contract->id}/handover-check-in", ['items' => []])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertSame($before, ContractHandoverReport::query()->sole()->items);
    }

    public function test_draft_cannot_be_finalized_until_check_in_is_confirmed(): void
    {
        $contract = $this->contract();
        $owner = $contract->room->boardingHouse->landlord->user;
        $this->putJson("/api/contracts/{$contract->id}", ['lessor_representative_user_id' => $owner->id])->assertOk();

        $this->postJson("/api/contracts/{$contract->id}/finalize")
            ->assertUnprocessable()->assertJsonPath('errors.handover_checkin.0', 'Vui lòng tạo và xác nhận biên bản bàn giao trước khi chốt hợp đồng.');
        $this->postJson("/api/contracts/{$contract->id}/handover-check-in")->assertCreated();
        $this->putJson("/api/contracts/{$contract->id}/status", ['status' => 'ACTIVE'])
            ->assertUnprocessable()->assertJsonValidationErrors('handover_checkin');
        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'status' => 'DRAFT', 'finalized_at' => null]);
        $this->assertDatabaseCount('contract_documents', 0);

        $this->postJson("/api/contracts/{$contract->id}/handover-check-in/confirm")->assertOk();
        $this->postJson("/api/contracts/{$contract->id}/finalize")->assertOk();

        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'status' => 'ACTIVE']);
        $this->assertStringContainsString('Ballet, nệm.', $contract->document()->sole()->rendered_html);
    }

    public function test_direct_active_create_cannot_skip_the_handover_step(): void
    {
        $existing = $this->contract();
        $owner = $existing->room->boardingHouse->landlord->user;

        $this->postJson("/api/rooms/{$existing->room_id}/contracts", [
            'tenant_id' => $existing->tenant_id, 'contract_code' => 'DIRECT-ACTIVE',
            'status' => 'ACTIVE', 'start_date' => '2028-01-01', 'monthly_rent' => 3000000,
            'lessor_representative_user_id' => $owner->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('handover_checkin');

        $this->assertDatabaseMissing('contracts', ['contract_code' => 'DIRECT-ACTIVE']);
        $this->assertDatabaseCount('contract_documents', 0);
    }

    public function test_handover_after_finalization_remains_separate_and_does_not_mutate_signed_source(): void
    {
        $contract = $this->contract();
        $oldHtml = '<p>Hợp đồng đã chốt trước khi có quy tắc bắt buộc bàn giao.</p>';
        $contract->update(['status' => 'ACTIVE', 'finalized_at' => now()]);
        ContractDocument::create([
            'contract_id' => $contract->id, 'template_name' => 'Mẫu cũ',
            'rendered_html' => $oldHtml, 'source_snapshot' => ['handover_checkin' => []],
            'document_hash' => hash('sha256', $oldHtml), 'finalized_at' => now(),
        ]);
        $before = $contract->document()->sole()->only(['rendered_html', 'document_hash', 'source_snapshot']);

        $this->postJson("/api/contracts/{$contract->id}/handover-check-in")->assertCreated();
        $this->postJson("/api/contracts/{$contract->id}/handover-check-in/confirm")->assertOk();

        $this->assertSame($before, $contract->document()->sole()->only(['rendered_html', 'document_hash', 'source_snapshot']));
        $this->getJson("/api/contracts/{$contract->id}/document")->assertOk()->assertJsonPath('data.rendered_html', $before['rendered_html']);
    }
}
