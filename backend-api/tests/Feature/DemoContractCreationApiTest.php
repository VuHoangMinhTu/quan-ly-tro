<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Database\Seeders\DemoContractTemplatesSeeder;
use Database\Seeders\RestoreDefaultContractTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as GoogleUser;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DemoContractCreationApiTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['ROOM_RENTAL', 'ROOM_RENTAL_DEMO'])]
    #[TestWith(['DORM_BED', 'DORM_BED_DEMO'])]
    public function test_demo_saves_customer_data_and_snapshot_but_cannot_activate(string $type, string $key): void
    {
        $this->seed([RestoreDefaultContractTemplatesSeeder::class, DemoContractTemplatesSeeder::class]);
        $base = Contract::factory()->create();
        $room = $base->room;
        $owner = $room->boardingHouse->landlord->user;
        $owner->forceFill(['contract_access' => 'DEMO'])->save();
        $base->tenant->update(['full_name' => 'Khách mới <script>test</script>']);
        $room->update(['room_type' => $type === 'DORM_BED' ? 'DORMITORY' : 'PRIVATE_ROOM']);
        $bed = $type === 'DORM_BED' ? $room->beds()->create(['bed_code' => 'G-TEST']) : null;
        $this->actingAs($owner, 'sanctum');
        $context = $this->getJson("/api/rooms/{$room->id}/contract-context")->assertOk()->assertJsonPath('data.demo_contracts_only', true)->json('data');
        $this->assertSame(['DORM_BED_DEMO', 'ROOM_RENTAL_DEMO'], collect($context['templates'])->pluck('template_key')->sort()->values()->all());
        $template = collect($context['templates'])->firstWhere('template_key', $key);
        $response = $this->postJson("/api/rooms/{$room->id}/contracts", [
            'tenant_id' => $base->tenant_id, 'contract_type' => $type, 'bed_id' => $bed?->id,
            'contract_template_id' => $template['id'], 'start_date' => '2026-11-01', 'end_date' => '2027-10-31',
            'monthly_rent' => 2345000, 'deposit_amount' => 123000, 'lessor_representative_user_id' => $owner->id,
            'status' => 'DRAFT', 'is_demo' => false, 'demo_document_snapshot' => ['rendered_html' => 'forged'],
        ])->assertCreated();
        $id = $response->json('data.id');
        $contract = Contract::findOrFail($id);
        $this->assertDatabaseHas('contracts', ['id' => $id, 'is_demo' => true, 'status' => 'DRAFT', 'contract_template_id' => $template['id']]);
        $saved = $contract->demo_document_snapshot;
        $this->assertSame($template['id'], $saved['template_id']);
        $this->assertSame('Khách mới <script>test</script>', $saved['source_snapshot']['tenant']['full_name']);
        $html = $saved['rendered_html'];
        $this->assertStringContainsString('Khách mới &lt;script&gt;test&lt;/script&gt;', $html);
        $this->assertStringContainsString('2.345.000', $html);
        $this->assertStringContainsString('123.000', $html);
        $this->assertStringNotContainsString('ĐIỀU 3:', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->getJson("/api/contracts/{$id}")->assertOk()->assertJsonPath('data.is_demo', true);
        ContractTemplate::findOrFail($template['id'])->update(['content_html' => '<p>Changed template</p>']);
        $this->getJson("/api/contracts/{$id}/document/preview")->assertOk()->assertJsonPath('data.rendered_html', $html)->assertJsonPath('data.is_demo', true);
        $this->putJson("/api/contracts/{$id}/status", ['status' => 'ACTIVE'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("/api/contracts/{$id}/finalize")->assertUnprocessable();
        $this->assertDatabaseHas('contracts', ['id' => $id, 'status' => 'DRAFT']);
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'status' => 'AVAILABLE']);
        if ($bed) {
            $this->assertDatabaseHas('beds', ['id' => $bed->id, 'status' => 'AVAILABLE']);
        }
        foreach (['room_tenants', 'contract_documents', 'invoices', 'payments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->actingAs(User::factory()->create(), 'sanctum')->getJson("/api/contracts/{$id}/document/preview")->assertNotFound();
    }

    public function test_demo_cannot_access_official_templates_or_forge_an_official_selection(): void
    {
        $this->seed([RestoreDefaultContractTemplatesSeeder::class, DemoContractTemplatesSeeder::class]);
        $base = Contract::factory()->create();
        $owner = $base->room->boardingHouse->landlord->user;
        $owner->forceFill(['contract_access' => 'DEMO'])->save();
        $official = ContractTemplate::where('template_key', 'ROOM_RENTAL_STANDARD')->firstOrFail();
        $this->actingAs($owner, 'sanctum');
        $this->getJson('/api/contract-templates')->assertOk()->assertJsonPath('data.can_manage', false)->assertJsonCount(2, 'data.templates');
        $this->getJson("/api/contract-templates/{$official->id}")->assertNotFound();
        $this->postJson("/api/rooms/{$base->room_id}/contracts", ['tenant_id' => $base->tenant_id, 'contract_template_id' => $official->id])->assertNotFound();
        $this->postJson("/api/contract-templates/{$official->id}/versions", ['name' => 'Copy', 'content_html' => '<p>copy</p>'])->assertForbidden();
        $this->assertDatabaseCount('contracts', 1);
        $this->assertDatabaseCount('contract_templates', 5);
    }

    public function test_full_default_selection_and_demo_seeder_preserve_official_content(): void
    {
        $this->seed(RestoreDefaultContractTemplatesSeeder::class);
        $before = ContractTemplate::orderBy('id')->get()->toArray();
        $this->seed(DemoContractTemplatesSeeder::class);
        $this->seed(DemoContractTemplatesSeeder::class);
        $this->assertSame($before, ContractTemplate::whereNotIn('template_key', ['ROOM_RENTAL_DEMO', 'DORM_BED_DEMO'])->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('contract_templates', 5);
        $base = Contract::factory()->create();
        $this->actingAs($base->room->boardingHouse->landlord->user, 'sanctum');
        $this->postJson("/api/rooms/{$base->room_id}/contracts", ['tenant_id' => $base->tenant_id, 'contract_type' => 'ROOM_RENTAL'])->assertCreated();
        $created = Contract::latest('id')->firstOrFail();
        $this->assertFalse($created->is_demo);
        $this->assertSame('ROOM_RENTAL_STANDARD', $created->contractTemplate->template_key);
        $preview = $this->getJson("/api/contracts/{$created->id}/document/preview")->assertOk()->json('data.rendered_html');
        $this->assertStringContainsString('ĐIỀU 3:', $preview);
    }

    #[TestWith(['ROOM_RENTAL', 'ROOM_RENTAL_DEMO'])]
    #[TestWith(['DORM_BED', 'DORM_BED_DEMO'])]
    public function test_demo_defaults_match_type_and_saved_edits_refresh_only_the_demo_snapshot(string $type, string $key): void
    {
        $this->seed(DemoContractTemplatesSeeder::class);
        $base = Contract::factory()->create();
        $owner = $base->room->boardingHouse->landlord->user;
        $owner->forceFill(['contract_access' => 'DEMO'])->save();
        $this->actingAs($owner, 'sanctum');
        $id = $this->postJson("/api/rooms/{$base->room_id}/contracts", ['tenant_id' => $base->tenant_id, 'contract_type' => $type, 'monthly_rent' => 1100000])->assertCreated()->json('data.id');
        $record = Contract::findOrFail($id);
        $this->assertSame($key, $record->contractTemplate->template_key);
        $wrong = ContractTemplate::where('template_key', $type === 'DORM_BED' ? 'ROOM_RENTAL_DEMO' : 'DORM_BED_DEMO')->firstOrFail();
        $before = $record->demo_document_snapshot;
        $this->putJson("/api/contracts/{$id}", ['contract_template_id' => $wrong->id])->assertUnprocessable()->assertJsonValidationErrors('contract_template_id');
        $this->assertSame($before, $record->fresh()->demo_document_snapshot);
        $this->putJson("/api/contracts/{$id}", ['monthly_rent' => 3456000])->assertOk();
        $preview = $this->getJson("/api/contracts/{$id}/document/preview")->assertOk()->json('data');
        $this->assertStringContainsString('3.456.000', $preview['rendered_html']);
        $this->assertSame($preview['rendered_html'], $record->fresh()->demo_document_snapshot['rendered_html']);
        $this->assertSame($record->contract_template_id, $record->fresh()->contract_template_id);
    }

    public function test_demo_manager_cannot_read_a_full_house_official_document_or_select_its_template(): void
    {
        $this->seed([RestoreDefaultContractTemplatesSeeder::class, DemoContractTemplatesSeeder::class]);
        $base = Contract::factory()->create();
        $manager = User::factory()->create();
        $manager->forceFill(['contract_access' => 'DEMO'])->save();
        $base->room->boardingHouse->managers()->attach($manager);
        $this->actingAs($manager, 'sanctum');
        $this->getJson("/api/contracts/{$base->id}/document/preview")->assertNotFound();
        $context = $this->getJson("/api/rooms/{$base->room_id}/contract-context")->assertOk()->json('data');
        $this->assertTrue($context['demo_contracts_only']);
        $this->assertSame(['DORM_BED_DEMO', 'ROOM_RENTAL_DEMO'], collect($context['templates'])->pluck('template_key')->sort()->values()->all());
        $official = ContractTemplate::where('template_key', 'ROOM_RENTAL_STANDARD')->firstOrFail();
        $this->postJson("/api/rooms/{$base->room_id}/contracts", ['tenant_id' => $base->tenant_id, 'contract_template_id' => $official->id])->assertNotFound();
        $this->assertDatabaseCount('contracts', 1);
    }

    #[TestWith(['ROOM_RENTAL'])]
    #[TestWith(['DORM_BED'])]
    public function test_demo_handover_changes_refresh_print_snapshot_and_keep_two_unsigned_parties(string $type): void
    {
        $this->seed(DemoContractTemplatesSeeder::class);
        $base = Contract::factory()->create();
        $owner = $base->room->boardingHouse->landlord->user;
        $owner->forceFill(['contract_access' => 'DEMO'])->save();
        $this->actingAs($owner, 'sanctum');
        $id = $this->postJson("/api/rooms/{$base->room_id}/contracts", ['tenant_id' => $base->tenant_id, 'contract_type' => $type])->assertCreated()->json('data.id');
        $this->postJson("/api/contracts/{$id}/handover-check-in")->assertCreated();
        $this->putJson("/api/contracts/{$id}/handover-check-in", ['items' => [['name' => 'Đèn bàn riêng', 'quantity' => 2, 'condition' => 'GOOD', 'note' => 'Đã kiểm tra thiết bị']]])->assertOk();
        $draft = $this->getJson("/api/contracts/{$id}/document/preview")->assertOk()->json('data');
        $this->assertSame('DRAFT', $draft['source_snapshot']['handover_status']);
        $this->assertSame(2, $draft['source_snapshot']['handover_checkin'][0]['quantity']);
        $this->assertStringContainsString('Đèn bàn riêng', $draft['rendered_html']);
        $this->assertStringContainsString('Đã kiểm tra thiết bị', $draft['rendered_html']);
        $this->assertStringNotContainsString('Tủ lạnh', $draft['rendered_html']);
        $this->postJson("/api/contracts/{$id}/handover-check-in/confirm")->assertOk();
        $snapshot = Contract::findOrFail($id)->demo_document_snapshot;
        $this->assertSame('CONFIRMED', $snapshot['source_snapshot']['handover_status']);
        $this->assertStringContainsString('BÊN A', $snapshot['rendered_html']);
        $this->assertStringContainsString('BÊN B', $snapshot['rendered_html']);
        $this->assertSame(2, substr_count($snapshot['rendered_html'], 'class="signature-handwritten-space"'));
        $this->assertStringNotContainsString('Chưa ký', $snapshot['rendered_html']);
        $this->assertStringNotContainsString('ĐIỀU 3:', $snapshot['rendered_html']);
        $this->putJson("/api/contracts/{$id}/status", ['status' => 'ACTIVE'])->assertUnprocessable();
        $this->assertDatabaseCount('room_tenants', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_email_registration_cannot_grant_itself_full_access(): void
    {
        Notification::fake();
        $this->postJson('/api/register', ['name' => 'Khách trải nghiệm', 'email' => 'demo@example.invalid', 'phone' => '0000000000', 'password' => 'password123', 'contract_access' => 'FULL'])->assertCreated();
        $user = User::where('email', 'demo@example.invalid')->firstOrFail();
        $this->assertSame('DEMO', $user->contract_access);
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_new_google_accounts_receive_demo_and_existing_full_accounts_keep_full(): void
    {
        $provider = $this->mock(GoogleProvider::class);
        $provider->shouldReceive('stateless')->twice()->andReturnSelf();
        $provider->shouldReceive('user')->twice()->andReturn((new GoogleUser)->map(['id' => 'synthetic-google-id', 'name' => 'Google Test', 'email' => 'google@example.invalid', 'avatar' => null]));
        Socialite::shouldReceive('driver')->with('google')->twice()->andReturn($provider);
        $this->get('/api/auth/google/callback')->assertRedirect();
        $user = User::where('email', 'google@example.invalid')->firstOrFail();
        $this->assertSame('DEMO', $user->contract_access);
        $user->forceFill(['contract_access' => 'FULL'])->save();
        $this->get('/api/auth/google/callback')->assertRedirect();
        $this->assertSame('FULL', $user->fresh()->contract_access);
    }
}
