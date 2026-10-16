<?php

namespace Tests\Feature;

use App\Models\BoardingHouse;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\RoomTenant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Asia/Ho_Chi_Minh']);
        $this->travelTo('2026-10-16 12:00:00');
    }

    private function graph(): array
    {
        $contract = Contract::factory()->create(['status' => 'ACTIVE', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31']);

        return [$contract->room->boardingHouse->landlord->user, $contract->room->boardingHouse, $contract->room, $contract];
    }

    private function login(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('dashboard-test')->plainTextToken);
    }

    private function invoice($room, array $values = []): Invoice
    {
        return $room->invoices()->create([
            'invoice_code' => 'INV-'.(Invoice::withTrashed()->count() + 1), 'billing_period' => '2026-10-01',
            'status' => 'UNPAID', 'subtotal' => 306720, 'discount_amount' => 0,
            'total_amount' => 306720, 'paid_amount' => 0, 'due_date' => '2026-10-20', ...$values,
        ]);
    }

    public function test_dashboard_requires_bearer_authentication(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_new_account_and_manager_without_assignment_get_safe_empty_results(): void
    {
        $user = User::factory()->create();
        $this->login($user)->getJson('/api/dashboard')->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.total_boarding_houses', 0)->assertJsonPath('data.summary.total_rooms', 0)
            ->assertJsonPath('data.summary.occupancy_rate', '0.00')->assertJsonPath('data.summary.active_tenants', 0)
            ->assertJsonPath('data.invoice_summary.invoice_total', '0.00')->assertJsonPath('data.invoice_summary.overdue_amount', '0.00')
            ->assertJsonCount(6, 'data.revenue_chart')->assertJsonPath('data.revenue_chart.5.amount', '0.00')
            ->assertJsonPath('data.boarding_houses', [])->assertJsonPath('data.recent_unpaid_invoices', []);
    }

    public function test_owner_counts_actual_occupancy_distinct_tenants_and_current_contract_dates(): void
    {
        [$user, $house, $room, $contract] = $this->graph();
        $house->rooms()->create(['room_code' => '102', 'monthly_rent' => null, 'status' => 'RENTED']);
        RoomTenant::create(['room_id' => $room->id, 'tenant_id' => $contract->tenant_id, 'status' => 'ACTIVE', 'is_primary' => true, 'move_in_date' => '2026-10-01']);
        RoomTenant::create(['room_id' => $room->id, 'tenant_id' => $contract->tenant_id, 'status' => 'ACTIVE', 'move_in_date' => '2026-10-02']);
        $oldTenant = Tenant::create(['landlord_id' => $house->landlord_id, 'full_name' => 'Đã chuyển đi']);
        RoomTenant::create(['room_id' => $room->id, 'tenant_id' => $oldTenant->id, 'status' => 'MOVED_OUT', 'move_in_date' => '2026-09-01', 'move_out_date' => '2026-09-30']);
        Contract::factory()->create(['room_id' => $room->id, 'tenant_id' => $contract->tenant_id, 'status' => 'DRAFT']);
        Contract::factory()->create(['room_id' => $room->id, 'tenant_id' => $contract->tenant_id, 'status' => 'EXPIRED']);
        Contract::factory()->create(['room_id' => $room->id, 'tenant_id' => $contract->tenant_id, 'status' => 'TERMINATED']);
        Contract::factory()->create(['room_id' => $room->id, 'tenant_id' => $contract->tenant_id, 'status' => 'ACTIVE', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
        $this->graph();

        $this->login($user)->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.summary.total_boarding_houses', 1)->assertJsonPath('data.summary.total_rooms', 2)
            ->assertJsonPath('data.summary.occupied_rooms', 1)->assertJsonPath('data.summary.available_rooms', 1)
            ->assertJsonPath('data.summary.occupancy_rate', '50.00')->assertJsonPath('data.summary.active_tenants', 1)
            ->assertJsonPath('data.summary.active_contracts', 1)->assertJsonPath('data.summary.expiring_soon_contracts', 1)
            ->assertJsonPath('data.summary.occupancy_status_mismatches', 2)
            ->assertJsonPath('data.expiring_contracts.0.end_date', '2026-10-31')->assertJsonPath('data.expiring_contracts.0.days_remaining', 15);
        $this->getJson("/api/rooms/{$room->id}")->assertOk()->assertJsonPath('data.active_occupants_count', 2);
    }

    public function test_invoice_financials_exclude_drafts_cancelled_and_other_periods_and_do_not_add_vat_again(): void
    {
        [$user, , $room] = $this->graph();
        $this->invoice($room);
        $this->invoice($room, ['total_amount' => 400000, 'paid_amount' => 100000, 'status' => 'PARTIALLY_PAID', 'due_date' => '2026-10-15']);
        $this->invoice($room, ['total_amount' => 200000, 'paid_amount' => 200000, 'status' => 'PAID']);
        $this->invoice($room, ['total_amount' => 900000, 'status' => 'DRAFT', 'due_date' => '2026-10-01']);
        $this->invoice($room, ['total_amount' => 999999, 'status' => 'CANCELLED', 'due_date' => '2026-10-01']);
        $this->invoice($room, ['total_amount' => 1000, 'billing_period' => '2026-09-01', 'due_date' => '2026-09-20']);

        $this->login($user)->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.invoice_summary.invoice_count', 3)->assertJsonPath('data.invoice_summary.invoice_total', '906720.00')
            ->assertJsonPath('data.invoice_summary.paid_amount', '300000.00')->assertJsonPath('data.invoice_summary.outstanding_amount', '606720.00')
            ->assertJsonPath('data.invoice_summary.unpaid_invoice_count', 3)->assertJsonPath('data.invoice_summary.overdue_invoice_count', 2)
            ->assertJsonPath('data.invoice_summary.overdue_amount', '301000.00')
            ->assertJsonPath('data.invoice_summary.statuses.PARTIALLY_PAID', 1)
            ->assertJsonPath('data.boarding_houses.0.outstanding_amount', '606720.00')
            ->assertJsonPath('data.period.label', '10/2026');
    }

    public function test_overdue_uses_business_date_not_today_or_null_and_recent_list_is_bounded(): void
    {
        [$user, , $room] = $this->graph();
        $overdue = $this->invoice($room, ['due_date' => '2026-10-15']);
        $this->invoice($room, ['due_date' => '2026-10-16']);
        $this->invoice($room, ['due_date' => null]);
        for ($index = 0; $index < 10; $index++) {
            $this->invoice($room, ['due_date' => '2026-10-25']);
        }

        $this->login($user)->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.invoice_summary.overdue_invoice_count', 1)
            ->assertJsonCount(8, 'data.recent_unpaid_invoices')->assertJsonPath('data.recent_unpaid_invoices.0.id', $overdue->id)
            ->assertJsonPath('data.recent_unpaid_invoices.0.due_date', '2026-10-15')->assertJsonPath('data.recent_unpaid_invoices.0.is_overdue', true)
            ->assertJsonMissingPath('data.recent_unpaid_invoices.0.contract')->assertJsonMissingPath('data.recent_unpaid_invoices.0.identity_number');
        $this->assertDatabaseHas('invoices', ['id' => $overdue->id, 'status' => 'UNPAID']);
    }

    public function test_cash_chart_uses_payment_paid_at_not_billing_period_and_includes_payos_and_decimal_amounts(): void
    {
        [$user, , $room] = $this->graph();
        $invoice = $this->invoice($room, ['billing_period' => '2026-01-01', 'status' => 'PAID']);
        foreach ([['2026-05-01 00:00:00', '123.45', 'CASH'], ['2026-09-30 23:59:59', '200000.00', 'PAYOS'], ['2026-10-01 00:00:00', '100000.00', 'CASH'], ['2026-04-30 23:59:59', '999.00', 'CASH'], ['2026-11-01 00:00:00', '999.00', 'CASH']] as [$date, $amount, $method]) {
            $invoice->payments()->create(['amount' => $amount, 'payment_method' => $method, 'paid_at' => $date]);
        }
        $cancelled = $this->invoice($room, ['status' => 'CANCELLED']);
        $cancelled->payments()->create(['amount' => 777777, 'payment_method' => 'CASH', 'paid_at' => '2026-10-10']);
        $this->login($user)->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.revenue_chart.0.month', '2026-05')->assertJsonPath('data.revenue_chart.0.amount', '123.45')
            ->assertJsonPath('data.revenue_chart.1.amount', '0.00')->assertJsonPath('data.revenue_chart.4.amount', '200000.00')
            ->assertJsonPath('data.revenue_chart.5.amount', '100000.00')->assertJsonPath('data.invoice_summary.paid_amount', '0.00');
    }

    public function test_manager_scope_includes_only_assigned_houses_for_every_section_and_revocation_is_immediate(): void
    {
        [$owner, $house, $room, $contract] = $this->graph();
        $second = BoardingHouse::create(['landlord_id' => $house->landlord_id, 'name' => 'Không phân công', 'address' => 'Private']);
        $otherRoom = $second->rooms()->create(['room_code' => '999', 'monthly_rent' => 1, 'status' => 'AVAILABLE']);
        Contract::factory()->create(['room_id' => $otherRoom->id, 'status' => 'ACTIVE', 'start_date' => '2026-10-01', 'end_date' => '2026-10-20']);
        $invoice = $this->invoice($room, ['contract_id' => $contract->id, 'status' => 'PARTIALLY_PAID', 'paid_amount' => 10]);
        $invoice->payments()->create(['amount' => 10, 'payment_method' => 'CASH', 'paid_at' => '2026-10-10']);
        $secret = $this->invoice($otherRoom, ['total_amount' => 9999999, 'status' => 'PARTIALLY_PAID', 'paid_amount' => 999]);
        $secret->payments()->create(['amount' => 999, 'payment_method' => 'CASH', 'paid_at' => '2026-10-10']);
        $manager = User::factory()->create();
        $house->managers()->attach($manager);

        $this->login($manager)->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.summary.total_boarding_houses', 1)
            ->assertJsonPath('data.summary.total_rooms', 1)->assertJsonPath('data.summary.active_contracts', 1)
            ->assertJsonPath('data.invoice_summary.invoice_total', '306720.00')->assertJsonPath('data.invoice_summary.paid_amount', '10.00')
            ->assertJsonPath('data.revenue_chart.5.amount', '10.00')->assertJsonCount(1, 'data.boarding_houses')
            ->assertJsonCount(1, 'data.recent_unpaid_invoices')->assertJsonCount(1, 'data.expiring_contracts')
            ->assertJsonMissing(['name' => 'Không phân công']);
        $house->managers()->detach($manager);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.summary.total_boarding_houses', 0)->assertJsonPath('data.revenue_chart.5.amount', '0.00');
        $this->login($owner)->getJson('/api/dashboard')->assertJsonPath('data.summary.total_boarding_houses', 2);
    }

    public function test_manager_can_follow_invoice_link_read_payments_but_cannot_write_financial_data(): void
    {
        [, $house, $room] = $this->graph();
        $invoice = $this->invoice($room);
        $manager = User::factory()->create();
        $house->managers()->attach($manager);
        $this->login($manager)->getJson("/api/invoices/{$invoice->id}")->assertOk()->assertJsonPath('data.can_manage', false);
        $this->getJson("/api/invoices/{$invoice->id}/payments")->assertOk()->assertJsonPath('data', []);
        $this->postJson("/api/invoices/{$invoice->id}/generate")->assertNotFound();
        $this->deleteJson("/api/invoices/{$invoice->id}")->assertNotFound();
        $foreign = Contract::factory()->create();
        $foreignInvoice = $this->invoice($foreign->room);
        $this->getJson("/api/invoices/{$foreignInvoice->id}")->assertNotFound();
        $this->getJson("/api/invoices/{$foreignInvoice->id}/payments")->assertNotFound();
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'UNPAID', 'paid_amount' => 0]);
    }

    public function test_deleted_parent_data_is_not_counted_and_query_count_does_not_grow_per_house(): void
    {
        [$user, $house, $room, $contract] = $this->graph();
        $this->invoice($room, ['contract_id' => $contract->id]);
        for ($index = 1; $index < 10; $index++) {
            $house->rooms()->create(['room_code' => 'extra-'.$index, 'monthly_rent' => null, 'status' => 'AVAILABLE']);
        }
        $this->login($user);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/dashboard')->assertOk();
        $baseline = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($index = 0; $index < 99; $index++) {
            $extra = BoardingHouse::create(['landlord_id' => $house->landlord_id, 'name' => 'House '.$index, 'address' => 'Test']);
            for ($roomIndex = 0; $roomIndex < 10; $roomIndex++) {
                $extra->rooms()->create(['room_code' => 'P'.$roomIndex, 'monthly_rent' => null, 'status' => 'AVAILABLE']);
            }
        }
        $deleted = $house->rooms()->create(['room_code' => 'deleted', 'monthly_rent' => null, 'status' => 'AVAILABLE']);
        $deletedInvoice = $this->invoice($deleted);
        $deletedInvoice->payments()->create(['amount' => 888888, 'payment_method' => 'CASH', 'paid_at' => '2026-10-10']);
        $deleted->delete();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.summary.total_boarding_houses', 100)->assertJsonPath('data.summary.total_rooms', 1000)->assertJsonPath('data.invoice_summary.invoice_count', 1)->assertJsonPath('data.revenue_chart.5.amount', '0.00');
        $this->assertLessThanOrEqual($baseline, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }
}
