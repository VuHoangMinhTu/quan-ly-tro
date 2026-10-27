<?php

namespace Tests\Feature;

use App\Models\BoardingHouse;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Landlord;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_landlord_can_create_invoice_with_zero_backend_totals(): void
    {
        $u = User::factory()->create();
        $l = Landlord::create(['user_id' => $u->id, 'full_name' => $u->name, 'phone' => '1']);
        $b = BoardingHouse::create(['landlord_id' => $l->id, 'name' => 'H', 'address' => 'A']);
        $r = Room::create(['boarding_house_id' => $b->id, 'room_code' => 'P', 'monthly_rent' => 1, 'status' => 'AVAILABLE']);
        $contract = Contract::factory()->create(['room_id' => $r->id, 'status' => 'ACTIVE']);
        $contract->occupants()->create(['room_id' => $r->id, 'tenant_id' => $contract->tenant_id, 'is_primary' => true, 'status' => 'ACTIVE', 'move_in_date' => '2026-10-01']);
        $token = $u->createToken('t')->plainTextToken;
        $this->withToken($token)->postJson("/api/rooms/{$r->id}/invoices", ['contract_id' => $contract->id, 'invoice_code' => 'INV-1', 'billing_period' => '2026-10-15', 'discount_amount' => 0, 'status' => 'DRAFT', 'issued_at' => '2026-10-31', 'due_date' => '2026-11-05', 'subtotal' => 999])->assertCreated()->assertJsonPath('data', null);

        $this->assertDatabaseHas('invoices', ['room_id' => $r->id, 'invoice_code' => 'INV-1', 'subtotal' => 0, 'total_amount' => 0, 'paid_amount' => 0, 'billing_period' => '2026-10-01']);

        $invoice = Invoice::where('invoice_code', 'INV-1')->firstOrFail();
        $invoice->update(['paid_at' => '2026-10-31 13:45:00']);

        $invoiceResponse = $this->withToken($token)->getJson("/api/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.billing_period', '2026-10-01')
            ->assertJsonPath('data.issued_at', '2026-10-31')
            ->assertJsonPath('data.due_date', '2026-11-05');

        $this->assertMatchesRegularExpression('/^\\d{4}-\\d{2}-\\d{2}T.+$/', $invoiceResponse->json('data.paid_at'));

        $this->withToken($token)->getJson("/api/rooms/{$r->id}/invoices")
            ->assertOk()
            ->assertJsonPath('data.items.0.billing_period', '2026-10-01')
            ->assertJsonPath('data.items.0.issued_at', '2026-10-31')
            ->assertJsonPath('data.items.0.due_date', '2026-11-05');
    }

    public function test_room_invoice_list_searches_filters_and_does_not_leak_foreign_invoices(): void
    {
        $owner = User::factory()->create();
        $landlord = Landlord::create(['user_id' => $owner->id, 'full_name' => $owner->name, 'phone' => '2']);
        $house = BoardingHouse::create(['landlord_id' => $landlord->id, 'name' => 'BeeHouse', 'address' => 'A']);
        $room = Room::create(['boarding_house_id' => $house->id, 'room_code' => 'P101', 'room_name' => 'Studio A', 'status' => 'AVAILABLE']);
        $contract = Contract::factory()->create(['room_id' => $room->id]);
        $contract->tenant->update(['full_name' => 'Bùi Quang Duy']);
        Invoice::create(['landlord_id' => $landlord->id, 'room_id' => $room->id, 'contract_id' => $contract->id, 'invoice_code' => 'INV-DUY-10', 'billing_period' => '2026-10-01', 'status' => 'UNPAID']);
        Invoice::create(['landlord_id' => $landlord->id, 'room_id' => $room->id, 'invoice_code' => 'INV-PAID-11', 'billing_period' => '2026-11-01', 'status' => 'PAID']);

        $foreignOwner = User::factory()->create();
        $foreignLandlord = Landlord::create(['user_id' => $foreignOwner->id, 'full_name' => $foreignOwner->name, 'phone' => '3']);
        $foreignHouse = BoardingHouse::create(['landlord_id' => $foreignLandlord->id, 'name' => 'Foreign', 'address' => 'B']);
        $foreignRoom = Room::create(['boarding_house_id' => $foreignHouse->id, 'room_code' => 'P101', 'status' => 'AVAILABLE']);
        Invoice::create(['landlord_id' => $foreignLandlord->id, 'room_id' => $foreignRoom->id, 'invoice_code' => 'INV-DUY-10', 'billing_period' => '2026-10-01', 'status' => 'UNPAID']);

        $token = $owner->createToken('invoice-filter')->plainTextToken;
        foreach (['search=INV-DUY' => 1, 'search=P101' => 2, 'search=Studio%20A' => 2, 'search=Duy' => 1, 'status=PAID' => 1, 'month=10&year=2026' => 1] as $query => $count) {
            $this->withToken($token)->getJson("/api/rooms/{$room->id}/invoices?{$query}")->assertOk()->assertJsonCount($count, 'data.items');
        }
        $this->withToken($token)->getJson("/api/rooms/{$foreignRoom->id}/invoices?search=INV-DUY")->assertNotFound();
    }
}
