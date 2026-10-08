<?php

namespace Tests\Feature;

use App\Models\BoardingHouse; use App\Models\Invoice; use App\Models\Landlord; use App\Models\Room; use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceItemApiTest extends TestCase
{
    use RefreshDatabase;
    public function test_invoice_item_calculates_amount_and_recalculates_totals(): void
    {
        $u=User::factory()->create();$l=Landlord::create(['user_id'=>$u->id,'full_name'=>$u->name,'phone'=>'1']);$b=BoardingHouse::create(['landlord_id'=>$l->id,'name'=>'H','address'=>'A']);$r=Room::create(['boarding_house_id'=>$b->id,'room_code'=>'P','monthly_rent'=>1,'status'=>'AVAILABLE']);$i=Invoice::create(['room_id'=>$r->id,'invoice_code'=>'INV-1','billing_period'=>'2026-09-01','status'=>'DRAFT','discount_amount'=>10]);
        $this->withToken($u->createToken('t')->plainTextToken)->postJson("/api/invoices/{$i->id}/items",['type'=>'RENT','description'=>'Rent','quantity'=>2,'unit_price'=>100,'amount'=>999])->assertCreated()->assertJsonPath('data',null);

        $this->assertDatabaseHas('invoice_items',['invoice_id'=>$i->id,'amount'=>200]);$this->assertDatabaseHas('invoices',['id'=>$i->id,'subtotal'=>200,'total_amount'=>190]);
    }
}
