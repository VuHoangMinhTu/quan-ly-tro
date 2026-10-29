<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DocumentCodesApiTest extends TestCase
{
    use RefreshDatabase;

    private function documentContext(string $type, array $attributes = []): Contract
    {
        $contract = Contract::factory()->create($type === 'INVOICE'
            ? ['status' => 'ACTIVE', 'start_date' => '2026-01-01', 'end_date' => null, ...$attributes]
            : $attributes);
        if ($type === 'INVOICE') {
            $contract->room->update(['status' => 'RENTED']);
            $contract->occupants()->create([
                'room_id' => $contract->room_id, 'tenant_id' => $contract->tenant_id,
                'is_primary' => true, 'status' => 'ACTIVE', 'move_in_date' => '2026-01-01',
            ]);
        }

        return $contract;
    }

    private function createDocument(Contract $context, string $type, ?string $code, string $period = '2026-01-01'): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $owner = $context->room->boardingHouse->landlord->user;
        $this->withToken($owner->createToken('test')->plainTextToken);

        return $type === 'CONTRACT'
            ? $this->postJson("/api/rooms/{$context->room_id}/contracts", ['tenant_id' => $context->tenant_id, 'contract_code' => $code, 'status' => 'DRAFT', 'landlord_id' => 99999])
            : $this->postJson("/api/rooms/{$context->room_id}/invoices", ['contract_id' => $context->id, 'invoice_code' => $code, 'billing_period' => $period, 'status' => 'DRAFT', 'landlord_id' => 99999]);
    }

    #[TestWith(['CONTRACT', 'HD-2026-0001', 'HD-2026-0002'])]
    #[TestWith(['INVOICE', 'INV-2026-000001', 'INV-2026-000002'])]
    public function test_auto_and_manual_codes_are_scoped_to_landlord(string $type, string $first, string $second): void
    {
        $this->travelTo(now()->setDate(2026, 10, 24));
        $a = $this->documentContext($type);
        $b = $this->documentContext($type);
        $table = $type === 'CONTRACT' ? 'contracts' : 'invoices';
        $field = $type === 'CONTRACT' ? 'contract_code' : 'invoice_code';

        $this->createDocument($a, $type, null)->assertCreated();
        $this->createDocument($a, $type, null, '2026-02-01')->assertCreated();
        $this->createDocument($b, $type, null)->assertCreated();
        $this->createDocument($a, $type, '  CUSTOM  ', '2026-03-01')->assertCreated();
        $this->createDocument($a, $type, 'CUSTOM', '2026-04-01')->assertUnprocessable()
            ->assertJsonPath("errors.{$field}.0", ($type === 'CONTRACT' ? 'Mã hợp đồng' : 'Mã hóa đơn').' CUSTOM đã được sử dụng.');
        $this->createDocument($b, $type, 'CUSTOM', '2026-03-01')->assertCreated();

        foreach ([$first, $second, 'CUSTOM'] as $code) {
            $this->assertDatabaseHas($table, ['landlord_id' => $a->room->boardingHouse->landlord_id, $field => $code]);
        }
        $this->assertDatabaseHas($table, ['landlord_id' => $b->room->boardingHouse->landlord_id, $field => $first]);
        $this->assertDatabaseHas($table, ['landlord_id' => $b->room->boardingHouse->landlord_id, $field => 'CUSTOM']);
    }

    #[TestWith(['CONTRACT', 'HD-2026-0005', 'HD-2026-0006'])]
    #[TestWith(['INVOICE', 'INV-2026-000005', 'INV-2026-000006'])]
    public function test_auto_skips_manual_collision_and_sequence_rolls_back_atomically(string $type, string $manual, string $expected): void
    {
        $this->travelTo(now()->setDate(2026, 10, 24));
        $context = $this->documentContext($type);
        $landlord = $context->room->boardingHouse->landlord_id;
        DB::table('document_sequences')->insert(['landlord_id' => $landlord, 'document_type' => $type, 'year' => 2026, 'last_number' => 4]);
        $this->createDocument($context, $type, $manual)->assertCreated();
        DB::beginTransaction();
        $this->createDocument($context, $type, null, '2026-02-01')->assertCreated();
        DB::rollBack();
        $this->assertDatabaseHas('document_sequences', ['landlord_id' => $landlord, 'document_type' => $type, 'last_number' => 4]);

        $this->createDocument($context, $type, null, '2026-02-01')->assertCreated();

        $this->assertDatabaseHas($type === 'CONTRACT' ? 'contracts' : 'invoices', ['landlord_id' => $landlord, $type === 'CONTRACT' ? 'contract_code' : 'invoice_code' => $expected]);
        $this->assertDatabaseHas('document_sequences', ['landlord_id' => $landlord, 'document_type' => $type, 'last_number' => 6]);
    }

    public function test_manager_uses_house_landlord_sequence_and_cannot_access_foreign_room(): void
    {
        $context = Contract::factory()->create();
        $foreign = Contract::factory()->create();
        $manager = User::factory()->create();
        $context->room->boardingHouse->managers()->attach($manager);
        $this->withToken($manager->createToken('test')->plainTextToken)->postJson("/api/rooms/{$context->room_id}/contracts", ['tenant_id' => $context->tenant_id])->assertCreated();
        $this->postJson("/api/rooms/{$foreign->room_id}/contracts", ['tenant_id' => $foreign->tenant_id])->assertNotFound();

        $this->assertDatabaseHas('document_sequences', ['landlord_id' => $context->room->boardingHouse->landlord_id, 'document_type' => 'CONTRACT', 'last_number' => 1]);
    }

    public function test_invoice_code_can_change_only_in_unissued_unpaid_draft(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 24)->startOfDay());
        $context = $this->documentContext('INVOICE');
        $this->createDocument($context, 'INVOICE', 'OLD')->assertCreated();
        $invoice = Invoice::where('room_id', $context->room_id)->sole();
        $payload = ['contract_id' => $context->id, 'invoice_code' => 'NEW', 'billing_period' => '2026-01-01', 'status' => 'DRAFT'];
        $this->putJson("/api/invoices/{$invoice->id}", $payload)->assertOk();
        $invoice->refresh()->update(['status' => 'UNPAID']);
        $this->putJson("/api/invoices/{$invoice->id}", [...$payload, 'invoice_code' => 'OTHER', 'status' => 'UNPAID'])
            ->assertUnprocessable()->assertJsonValidationErrors('invoice_code');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'invoice_code' => 'NEW']);
    }

    #[TestWith(['contracts', 'contract_code'])]
    #[TestWith(['invoices', 'invoice_code'])]
    public function test_database_rejects_duplicate_code_even_when_model_validation_is_bypassed(string $table, string $field): void
    {
        $this->travelTo(now()->setDate(2026, 10, 24)->startOfDay());
        $context = $this->documentContext($table === 'invoices' ? 'INVOICE' : 'CONTRACT');
        if ($table === 'invoices') {
            $this->createDocument($context, 'INVOICE', 'EXISTING')->assertCreated();
        }
        $row = (array) DB::table($table)->where('room_id', $context->room_id)->first();
        unset($row['id']);
        if ($table === 'invoices') {
            $row['billing_period'] = '2026-02-01';
        }

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table($table)->insert($row);
    }

    public function test_sequence_is_shared_across_rooms_and_resets_by_year_and_document_type(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 24));
        $first = Contract::factory()->create();
        $room = Room::create(['boarding_house_id' => $first->room->boarding_house_id, 'room_code' => 'SECOND', 'status' => 'AVAILABLE']);
        $second = $this->documentContext('INVOICE', ['room_id' => $room->id, 'tenant_id' => $first->tenant_id]);
        $this->createDocument($first, 'CONTRACT', null)->assertCreated();
        $this->createDocument($second, 'CONTRACT', null)->assertCreated();
        $this->createDocument($second, 'INVOICE', null)->assertCreated();
        $this->travelTo(now()->setDate(2027, 1, 1));
        $this->createDocument($first, 'CONTRACT', null)->assertCreated();

        $this->assertDatabaseHas('contracts', ['room_id' => $second->room_id, 'contract_code' => 'HD-2026-0002']);
        $this->assertDatabaseHas('invoices', ['room_id' => $second->room_id, 'invoice_code' => 'INV-2026-000001']);
        $this->assertDatabaseHas('contracts', ['room_id' => $first->room_id, 'contract_code' => 'HD-2027-0001']);
    }

    public function test_migration_backfills_ownership_without_changing_existing_codes(): void
    {
        $original = config('database.default');
        config(['database.connections.document_migration_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('document_migration_test');
        try {
            Schema::create('landlords', fn (Blueprint $table) => $table->id());
            Schema::create('boarding_houses', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('landlord_id');
            });
            Schema::create('rooms', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('boarding_house_id');
            });
            DB::table('landlords')->insert(['id' => 7]);
            DB::table('boarding_houses')->insert(['id' => 11, 'landlord_id' => 7]);
            DB::table('rooms')->insert(['id' => 20, 'boarding_house_id' => 11]);
            foreach (['contracts' => 'contract_code', 'invoices' => 'invoice_code'] as $table => $field) {
                Schema::create($table, function (Blueprint $blueprint) use ($field): void {
                    $blueprint->id();
                    $blueprint->unsignedBigInteger('room_id');
                    $blueprint->string($field, 100)->unique();
                });
                DB::table($table)->insert(['id' => 50, 'room_id' => 20, $field => 'LEGACY-UNCHANGED']);
            }

            (require database_path('migrations/2026_10_24_091712_scope_document_codes_to_landlords.php'))->up();

            foreach (['contracts' => 'contract_code', 'invoices' => 'invoice_code'] as $table => $field) {
                $this->assertDatabaseHas($table, ['id' => 50, 'landlord_id' => 7, $field => 'LEGACY-UNCHANGED']);
                $column = collect(Schema::getColumns($table))->firstWhere('name', 'landlord_id');
                $this->assertFalse($column['nullable']);
            }
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('document_migration_test');
        }
    }
}
