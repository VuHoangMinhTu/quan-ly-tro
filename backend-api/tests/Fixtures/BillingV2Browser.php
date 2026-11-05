<?php

use App\Models\Contract;
use App\Models\Invoice;
use App\Services\BillingV2Evidence;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/BillingV2MysqlBootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'beehouse_billing_v2_test' || config('database.connections.mysql.host') !== '127.0.0.1' || (string) config('database.connections.mysql.port') !== '13368') {
    throw new RuntimeException('Isolated test database required.');
}
if (($argv[1] ?? '') === 'seed') {
    Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
    $a = Contract::factory()->create(['status' => 'ACTIVE', 'start_date' => '2026-10-01']);
    $room = $a->room;
    $user = $room->boardingHouse->landlord->user;
    $user->forceFill(['email' => 'billing-browser@example.invalid', 'email_verified_at' => now(), 'password' => Hash::make('BrowserTest123!'), 'contract_access' => 'FULL'])->save();
    $b = Contract::factory()->create(['room_id' => $room->id, 'status' => 'ACTIVE', 'start_date' => '2026-10-20']);
    foreach ([$a, $b] as $contract) {
        $contract->handoverReports()->create(['type' => 'CHECK_IN', 'status' => 'CONFIRMED', 'items' => [], 'confirmed_at' => now()]);
    }
    $service = $room->boardingHouse->services()->create(['name' => 'Điện giả lập', 'type' => 'ELECTRICITY', 'billing_method' => 'TIERED', 'base_price' => 0, 'unit' => 'kWh', 'is_active' => true]);
    $service->priceTiers()->createMany([['from_quantity' => 0, 'to_quantity' => 20, 'unit_price' => 2000, 'tier_order' => 1], ['from_quantity' => 20, 'to_quantity' => null, 'unit_price' => 4000, 'tier_order' => 2]]);
    foreach ([$a, $b] as $contract) {
        $contract->service_terms_version = 1;
        $contract->save();
        $contract->agreedServices()->create(['service_id' => $service->id, 'service_name' => $service->name, 'service_type' => $service->type, 'billing_method' => 'TIERED', 'base_price' => 0, 'unit' => 'kWh', 'effective_from' => $contract->start_date->toDateString(), 'is_active' => true, 'pricing_snapshot' => $service->priceTiers->map->only(['from_quantity', 'to_quantity', 'unit_price', 'tier_order'])->all()]);
    }
    $room->services()->attach($service, ['is_active' => true]);
    $waterService = $room->boardingHouse->services()->create(['name' => 'Nước giả lập', 'type' => 'WATER', 'billing_method' => 'PER_UNIT', 'base_price' => 1500, 'unit' => 'm³', 'is_active' => true]);
    $room->services()->attach($waterService, ['is_active' => true]);
    $dormitory = $room->boardingHouse->rooms()->create(['room_code' => 'KTX-SHARED-01', 'room_type' => 'DORMITORY', 'status' => 'AVAILABLE']);
    $dormitory->beds()->createMany([['bed_code' => 'G01', 'status' => 'OCCUPIED'], ['bed_code' => 'G02', 'status' => 'OCCUPIED']]);
    $dormitoryService = $room->boardingHouse->services()->create(['name' => 'Điện KTX giả lập', 'type' => 'ELECTRICITY', 'billing_method' => 'TIERED', 'base_price' => 0, 'unit' => 'kWh', 'is_active' => true]);
    $dormitoryService->priceTiers()->createMany([['from_quantity' => 0, 'to_quantity' => 20, 'unit_price' => 2000, 'tier_order' => 1], ['from_quantity' => 20, 'to_quantity' => null, 'unit_price' => 4000, 'tier_order' => 2]]);
    $dormitory->utilityMeters()->create(['service_id' => $dormitoryService->id, 'meter_code' => 'KTX-SHARED-METER', 'initial_reading' => 300, 'is_active' => true]);
    foreach ([$a, $b] as $contract) {
        $contract->agreedServices()->create(['service_id' => $waterService->id, 'service_name' => $waterService->name, 'service_type' => 'WATER', 'billing_method' => 'PER_UNIT', 'base_price' => 1500, 'unit' => 'm³', 'effective_from' => $contract->start_date->toDateString(), 'is_active' => true]);
    }
    $meter = $room->utilityMeters()->create(['service_id' => $service->id, 'initial_reading' => 100, 'is_active' => true]);
    $waterMeter = $room->utilityMeters()->create(['service_id' => $waterService->id, 'initial_reading' => 20, 'is_active' => true]);
    $evidence = app(BillingV2Evidence::class);
    $evidence->checkpoint($meter, ['kind' => 'PERIOD_BOUNDARY', 'observed_at' => '2026-10-01 00:00:00', 'reading_value' => 100], $user->id);
    $evidence->checkpoint($meter, ['kind' => 'CHECK_IN', 'contract_id' => $a->id, 'observed_at' => '2026-10-01 00:00:00', 'reading_value' => 100], $user->id);
    $evidence->checkpoint($waterMeter, ['kind' => 'PERIOD_BOUNDARY', 'observed_at' => '2026-10-01 00:00:00', 'reading_value' => 20], $user->id);
    $evidence->checkpoint($waterMeter, ['kind' => 'CHECK_IN', 'contract_id' => $a->id, 'observed_at' => '2026-10-01 00:00:00', 'reading_value' => 20], $user->id);
    echo json_encode(['user' => $user->id, 'house' => $room->boarding_house_id, 'room' => $room->id, 'ktxRoom' => $dormitory->id, 'a' => $a->id, 'b' => $b->id, 'meter' => $meter->id, 'waterMeter' => $waterMeter->id], JSON_THROW_ON_ERROR);
} elseif (($argv[1] ?? '') === 'assert-empty') {
    if (Invoice::count() !== 0 || DB::table('payments')->count() !== 0 || DB::table('payos_payment_requests')->count() !== 0) {
        throw new RuntimeException('Flag-off browser path created financial records.');
    }
    echo json_encode(['invoices' => 0, 'payments' => 0, 'payos_requests' => 0], JSON_THROW_ON_ERROR);
} elseif (($argv[1] ?? '') === 'assert-update-preview') {
    $invoice = Invoice::firstOrFail();
    if ($invoice->total_amount !== '1568000.00' || $invoice->items()->count() !== 4 || DB::table('monthly_draft_recalculations')->where('status', 'APPLIED')->exists()) {
        throw new RuntimeException('Preview changed money or items.');
    }
    echo json_encode(['preview_preserved_total' => 1568000, 'items' => 4]);
} elseif (($argv[1] ?? '') === 'assert-update') {
    $invoice = Invoice::firstOrFail();
    $electricity = DB::table('utility_charge_allocations as a')->join('utility_billing_periods as p', 'p.id', '=', 'a.utility_billing_period_id')->join('utility_meters as m', 'm.id', '=', 'p.utility_meter_id')->join('services as s', 's.id', '=', 'm.service_id')->where('s.type', 'ELECTRICITY')->sum('a.quantity');
    $water = DB::table('utility_charge_allocations as a')->join('utility_billing_periods as p', 'p.id', '=', 'a.utility_billing_period_id')->join('utility_meters as m', 'm.id', '=', 'p.utility_meter_id')->join('services as s', 's.id', '=', 'm.service_id')->where('s.type', 'WATER')->sum('a.quantity');
    if (Invoice::count() !== 1 || $invoice->status !== 'DRAFT' || $invoice->total_amount !== '1585454.00'
        || $invoice->items()->count() !== 4 || $invoice->items()->where('source', 'MANUAL')->value('amount') !== '5000.00'
        || DB::table('monthly_draft_recalculations')->where('status', 'APPLIED')->count() !== 1
        || (int) $electricity !== 55 || (int) $water !== 20
        || DB::table('utility_charge_allocations')->where('responsible_party', 'LANDLORD')->whereNotNull('invoice_item_id')->exists()
        || $invoice->payments()->exists() || $invoice->payosPaymentRequests()->exists()) {
        throw new RuntimeException('Confirmed draft update proof failed.');
    }
    echo json_encode(['invoices' => 1, 'items' => 4, 'total' => 1585454, 'electricity' => 65454, 'water' => 15000, 'manual' => 5000, 'applied_history_records' => 1, 'electricity_quantity' => 55, 'water_quantity' => 20, 'status' => 'DRAFT']);
} elseif (($argv[1] ?? '') === 'assert-water-update-preview') {
    $invoice = Invoice::firstOrFail();
    if ($invoice->total_amount !== '1585454.00' || $invoice->items()->count() !== 4 || DB::table('monthly_draft_recalculations')->where('status', 'APPLIED')->count() !== 1) {
        throw new RuntimeException('Water preview changed persisted invoice state.');
    }
    echo json_encode(['preview_preserved_total' => 1585454, 'items' => 4]);
} elseif (($argv[1] ?? '') === 'assert-water-update') {
    $invoice = Invoice::firstOrFail();
    $water = DB::table('utility_charge_allocations as a')->join('utility_billing_periods as p', 'p.id', '=', 'a.utility_billing_period_id')->join('utility_meters as m', 'm.id', '=', 'p.utility_meter_id')->join('services as s', 's.id', '=', 'm.service_id')->where('s.type', 'WATER')->sum('a.quantity');
    if (Invoice::count() !== 1 || $invoice->status !== 'DRAFT' || $invoice->total_amount !== '1592954.00' || $invoice->items()->count() !== 4
        || $invoice->items()->where('source', 'MANUAL')->value('amount') !== '5000.00' || $invoice->items()->where('type', 'WATER')->value('amount') !== '22500.00'
        || (int) $water !== 25 || DB::table('monthly_draft_recalculations')->where('status', 'APPLIED')->count() !== 2
        || $invoice->payments()->exists() || $invoice->payosPaymentRequests()->exists()) {
        throw new RuntimeException('Water draft recalculation proof failed.');
    }
    echo json_encode(['total' => 1592954, 'water_quantity' => 25, 'water_amount' => 22500, 'manual' => 5000, 'applied_history_records' => 2, 'status' => 'DRAFT']);
} elseif (($argv[1] ?? '') === 'assert-monthly') {
    $invoice = Invoice::firstOrFail();
    $electricity = DB::table('utility_charge_allocations as a')->join('utility_billing_periods as p', 'p.id', '=', 'a.utility_billing_period_id')->join('utility_meters as m', 'm.id', '=', 'p.utility_meter_id')->join('services as s', 's.id', '=', 'm.service_id')->where('s.type', 'ELECTRICITY')->sum('a.quantity');
    $water = DB::table('utility_charge_allocations as a')->join('utility_billing_periods as p', 'p.id', '=', 'a.utility_billing_period_id')->join('utility_meters as m', 'm.id', '=', 'p.utility_meter_id')->join('services as s', 's.id', '=', 'm.service_id')->where('s.type', 'WATER')->sum('a.quantity');
    $tenantAllocations = DB::table('utility_charge_allocations')->where('responsible_party', 'TENANT');
    $tenantQuantity = (float) (clone $tenantAllocations)->sum('quantity');
    $tenantTariff = (float) (clone $tenantAllocations)->sum('tariff_amount');
    $landlordQuantity = (float) DB::table('utility_charge_allocations')->where('responsible_party', 'LANDLORD')->sum('quantity');
    $abandonedId = DB::table('contract_settlements')->where('collection_policy', 'NO_NEW_AUTO_RECEIVABLES')->value('contract_id');
    $dormitoryId = DB::table('rooms')->where('room_type', 'DORMITORY')->value('id');
    if (Invoice::count() !== 1 || $invoice->status !== 'DRAFT' || $invoice->issued_at !== null || $invoice->total_amount !== '1563000.00'
        || $invoice->items()->where('source', 'AUTO')->count() !== 3 || $invoice->payments()->exists() || $invoice->payosPaymentRequests()->exists()
        || DB::table('monthly_invoice_compositions')->count() !== 1 || (int) $electricity !== 50 || (int) $water !== 20
        || $tenantQuantity !== 25.0 || $tenantTariff !== 63000.0 || $landlordQuantity !== 45.0
        || DB::table('utility_charge_allocations')->where('contract_id', $abandonedId)->where('responsible_party', 'TENANT')->exists()
        || DB::table('invoices')->where('room_id', $dormitoryId)->exists()
        || DB::table('utility_billing_periods')->whereIn('utility_meter_id', DB::table('utility_meters')->where('room_id', $dormitoryId)->select('id'))->exists()) {
        throw new RuntimeException('Monthly browser database proof failed.');
    }
    echo json_encode(['invoices' => 1, 'total' => 1563000, 'rent' => 1500000, 'electricity' => 48000, 'water' => 15000, 'tenant_quantity' => 25, 'tenant_tariff' => 63000, 'landlord_quantity' => 45, 'abandoned_tenant_receivables' => 0, 'status' => 'DRAFT', 'payments' => 0, 'payos_requests' => 0]);
} elseif (($argv[1] ?? '') === 'assert-pending') {
    $case = DB::table('contract_settlements')->firstOrFail();
    $usage = DB::table('contract_settlement_usages')->firstOrFail();
    if ($case->status !== 'PENDING_FINAL_SETTLEMENT' || $case->collection_policy !== 'NO_NEW_AUTO_RECEIVABLES'
        || $case->confirmation_state !== 'CONFIRMED' || $usage->checkout_period_quantity !== null || (float) $usage->quantity_since_check_in !== 30.0 || $usage->tariff_amount !== null
        || $usage->responsible_party !== 'LANDLORD' || DB::table('invoices')->count() !== 0 || DB::table('utility_billing_periods')->count() !== 0
        || Contract::findOrFail($case->contract_id)->status !== 'TERMINATED') {
        throw new RuntimeException('Checkout must complete before monthly close without pricing pending TIERED as zero.');
    }
    echo json_encode(['checkout_complete' => true, 'status' => 'PENDING_FINAL_SETTLEMENT', 'quantity' => 30, 'tariff_amount' => null, 'invoices' => 0]);
} elseif (($argv[1] ?? '') === 'assert') {
    $db = DB::class;
    $shares = $db::table('utility_charge_allocations')->get();
    if ($db::table('invoices')->count() !== 1 || $db::table('invoice_items')->count() !== 1 || $db::table('invoice_obligations')->count() !== 1
        || (int) $shares->sum('quantity') !== 50 || (int) $shares->sum('tariff_amount') !== 160000
        || (int) $shares->where('responsible_party', 'TENANT')->sum('quantity') !== 15 || (int) $db::table('invoice_items')->sum('amount') !== 48000
        || $db::table('contract_deposit_entries')->where('event', 'FORFEITED')->value('amount') !== '800000'
        || $shares->where('responsible_party', 'LANDLORD')->whereNotNull('invoice_item_id')->isNotEmpty() || $shares->whereNotNull('supplier_cost')->isNotEmpty()
        || DB::table('contract_settlements')->value('status') !== 'FINAL_RECONCILED') {
        throw new RuntimeException('Browser database proof failed.');
    }
    echo json_encode(['invoices' => 1, 'quantity' => 50, 'tariff_total' => 160000, 'tenant_charge' => 48000, 'owner_quantity' => 35, 'forfeited_actual_deposit' => 800000]);
} elseif (($argv[1] ?? '') === 'assert-combined') {
    $invoice = Invoice::firstOrFail();
    if ($invoice->status !== 'PAID' || $invoice->total_amount !== '4168000.00' || $invoice->paid_amount !== '4168000.00'
        || $invoice->items()->where('source', 'MANUAL')->count() !== 3 || $invoice->items()->where('source', 'AUTO')->count() !== 1
        || $invoice->payments()->count() !== 1 || DB::table('invoices')->count() !== 1
        || (int) DB::table('utility_charge_allocations')->where('responsible_party', 'TENANT')->sum('tariff_amount') !== 48000
        || DB::table('utility_charge_allocations')->where('responsible_party', 'LANDLORD')->whereNotNull('invoice_item_id')->exists()) {
        throw new RuntimeException('Combined browser invoice proof failed.');
    }
    echo json_encode(['total' => 4168000, 'utility_auto' => 48000, 'manual_rent_and_services' => 4120000, 'manual_items' => 3, 'payments' => 1, 'status' => 'PAID', 'automatic_monthly_fee_policy' => false]);
} else {
    throw new RuntimeException('Unknown fixture command.');
}
