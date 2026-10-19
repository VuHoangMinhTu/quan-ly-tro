<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\Contract;
use App\Models\ContractHandoverReport;
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
        $this->assertCount(7, $items);
        $this->assertCount(1, array_filter($items, fn (array $item) => $item['name'] === 'Máy lạnh'));
        $this->assertContains('Tivi', array_column($items, 'name'));
        $this->postJson("/api/contracts/{$contract->id}/handover-check-in")->assertUnprocessable();
    }

    public function test_edit_confirm_and_preview_only_include_confirmed_check_in(): void
    {
        $contract = $this->contract();
        $this->postJson("/api/contracts/{$contract->id}/handover-check-in")->assertCreated();
        $items = [['name' => 'Tivi', 'quantity' => 2, 'condition' => 'DAMAGED', 'note' => 'Trầy nhẹ']];
        $this->putJson("/api/contracts/{$contract->id}/handover-check-in", ['items' => $items])->assertOk();
        $draft = $this->getJson("/api/contracts/{$contract->id}/document/preview")->assertOk()->json('data.rendered_html');
        $this->assertStringContainsString('Chưa có biên bản bàn giao', $draft);

        $this->postJson("/api/contracts/{$contract->id}/handover-check-in/confirm")->assertOk();
        $confirmed = $this->getJson("/api/contracts/{$contract->id}/document/preview")->assertOk()->json('data.rendered_html');

        $this->assertStringContainsString('Tivi', $confirmed);
        $this->assertStringContainsString('Hư hỏng', $confirmed);
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
    }

    public function test_handover_after_finalization_remains_separate_and_does_not_mutate_signed_source(): void
    {
        $contract = $this->contract();
        $owner = $contract->room->boardingHouse->landlord->user;
        $this->putJson("/api/contracts/{$contract->id}", ['lessor_representative_user_id' => $owner->id])->assertOk();
        $this->postJson("/api/contracts/{$contract->id}/finalize")->assertOk();
        $before = $contract->document()->sole()->only(['rendered_html', 'document_hash', 'source_snapshot']);

        $this->postJson("/api/contracts/{$contract->id}/handover-check-in")->assertCreated();
        $this->postJson("/api/contracts/{$contract->id}/handover-check-in/confirm")->assertOk();

        $this->assertSame($before, $contract->document()->sole()->only(['rendered_html', 'document_hash', 'source_snapshot']));
        $this->getJson("/api/contracts/{$contract->id}/document")->assertOk()->assertJsonPath('data.rendered_html', $before['rendered_html']);
    }
}
