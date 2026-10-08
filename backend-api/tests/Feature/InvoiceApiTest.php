<?php

namespace Tests\Feature;

use App\Models\BoardingHouse; use App\Models\Invoice; use App\Models\Landlord; use App\Models\Room; use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceApiTest extends TestCase
{
    use RefreshDatabase;
    public function test_landlord_can_create_invoice_with_zero_backend_totals(): void
    {
        $u=User::factory()->create();$l=Landlord::create(['user_id'=>$u->id,'full_name'=>$u->name,'phone'=>'1']);$b=BoardingHouse::create(['landlord_id'=>$l->id,'name'=>'H','address'=>'A']);$r=Room::create(['boarding_house_id'=>$b->id,'room_code'=>'P','monthly_rent'=>1,'status'=>'AVAILABLE']);
        $token = $u->createToken('t')->plainTextToken;
        $this->withToken($token)->postJson("/api/rooms/{$r->id}/invoices",['invoice_code'=>'INV-1','billing_period'=>'2026-10-15','discount_amount'=>0,'status'=>'DRAFT','issued_at'=>'2026-10-31','due_date'=>'2026-11-05','subtotal'=>999])->assertCreated()->assertJsonPath('data',null);

        $this->assertDatabaseHas('invoices',['room_id'=>$r->id,'invoice_code'=>'INV-1','subtotal'=>0,'total_amount'=>0,'paid_amount'=>0,'billing_period'=>'2026-10-01']);

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
            ->assertJsonPath('data.0.billing_period', '2026-10-01')
            ->assertJsonPath('data.0.issued_at', '2026-10-31')
            ->assertJsonPath('data.0.due_date', '2026-11-05');
    }
}
