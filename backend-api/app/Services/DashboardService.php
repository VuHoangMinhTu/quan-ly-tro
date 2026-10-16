<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomTenant;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    private const EXPIRING_DAYS = 30;

    private const RECENT_INVOICES = 8;

    private const RECENT_CONTRACTS = 5;

    public function __construct(private BoardingHouseAccessService $access) {}

    private function rooms(User $user): Builder
    {
        return Room::query()->whereIn('boarding_house_id', $this->access->houses($user)->select('boarding_houses.id'));
    }

    private function invoices(User $user): Builder
    {
        return Invoice::query()->whereIn('room_id', $this->rooms($user)->select('rooms.id'));
    }

    private function money(mixed $value): string
    {
        // MySQL SUM(DECIMAL) stays decimal. Do not cast financial values to float.
        return (string) BigDecimal::of((string) ($value ?? '0'))->toScale(2, RoundingMode::HalfUp);
    }

    private function rate(int $occupied, int $rooms): string
    {
        return $rooms === 0 ? '0.00' : (string) BigDecimal::of($occupied)->multipliedBy(100)->dividedBy($rooms, 2, RoundingMode::HalfUp);
    }

    public function summary(User $user): array
    {
        $today = CarbonImmutable::today(config('app.timezone'));
        $start = $today->startOfMonth();
        $end = $start->addMonth();
        $houses = $this->access->houses($user)->with('landlord')->orderBy('name')->orderBy('id')->get(['id', 'landlord_id', 'name']);
        $roomRows = $this->rooms($user)->select(['rooms.id', 'boarding_house_id', 'status'])->withActiveOccupancy();
        $roomStats = DB::query()->fromSub($roomRows->toBase(), 'scoped_rooms')
            ->select('boarding_house_id')->selectRaw('COUNT(*) AS total_rooms')
            ->selectRaw('SUM(CASE WHEN active_occupants_count > 0 THEN 1 ELSE 0 END) AS occupied_rooms')
            ->selectRaw("SUM(CASE WHEN (status = 'RENTED' AND active_occupants_count = 0) OR (status <> 'RENTED' AND active_occupants_count > 0) THEN 1 ELSE 0 END) AS occupancy_status_mismatches")
            ->groupBy('boarding_house_id')->get()->keyBy('boarding_house_id');

        $activeTenants = RoomTenant::query()->whereIn('room_id', $this->rooms($user)->select('rooms.id'))
            ->where('status', 'ACTIVE')->whereHas('tenant')->distinct()->count('tenant_id');
        $contracts = Contract::query()->whereIn('room_id', $this->rooms($user)->select('rooms.id'))->where('status', 'ACTIVE')
            ->where('start_date', '<=', $today->toDateString())
            ->where(fn ($query) => $query->whereNull('end_date')->orWhere('end_date', '>=', $today->toDateString()));
        $contractStats = (clone $contracts)->toBase()->selectRaw('COUNT(*) AS active_contracts')
            ->selectRaw('COUNT(CASE WHEN end_date <= ? THEN 1 END) AS expiring_soon_contracts', [$today->addDays(self::EXPIRING_DAYS)->toDateString()])->first();

        $monthInvoices = $this->invoices($user)->whereIn('invoices.status', ['UNPAID', 'PARTIALLY_PAID', 'PAID'])
            ->where('invoices.billing_period', '>=', $start->toDateString())->where('invoices.billing_period', '<', $end->toDateString());
        $invoiceStats = (clone $monthInvoices)->toBase()->selectRaw('COUNT(*) AS invoice_count, COALESCE(SUM(total_amount), 0) AS invoice_total, COALESCE(SUM(paid_amount), 0) AS paid_amount, COALESCE(SUM(total_amount - paid_amount), 0) AS outstanding_amount')
            ->selectRaw("COUNT(CASE WHEN status = 'PAID' THEN 1 END) AS paid_count, COUNT(CASE WHEN status = 'UNPAID' THEN 1 END) AS unpaid_count, COUNT(CASE WHEN status = 'PARTIALLY_PAID' THEN 1 END) AS partially_paid_count")->first();
        $unpaid = $this->invoices($user)->whereIn('status', ['UNPAID', 'PARTIALLY_PAID'])->whereColumn('total_amount', '>', 'paid_amount');
        $collectionStats = (clone $unpaid)->toBase()->selectRaw('COUNT(*) AS unpaid_invoice_count, COALESCE(SUM(total_amount - paid_amount), 0) AS unpaid_amount')
            ->selectRaw('COUNT(CASE WHEN due_date < ? THEN 1 END) AS overdue_invoice_count', [$today->toDateString()])
            ->selectRaw('COALESCE(SUM(CASE WHEN due_date < ? THEN total_amount - paid_amount ELSE 0 END), 0) AS overdue_amount', [$today->toDateString()])->first();

        $houseInvoiceStats = (clone $monthInvoices)->join('rooms', 'rooms.id', '=', 'invoices.room_id')
            ->toBase()->select('rooms.boarding_house_id')->selectRaw('SUM(invoices.total_amount - invoices.paid_amount) AS outstanding_amount')
            ->groupBy('rooms.boarding_house_id')->get()->keyBy('boarding_house_id');
        $houseData = $houses->map(function ($house) use ($roomStats, $houseInvoiceStats, $user): array {
            $stats = $roomStats->get($house->id);
            $total = (int) ($stats?->total_rooms ?? 0);
            $occupied = (int) ($stats?->occupied_rooms ?? 0);

            return ['id' => $house->id, 'name' => $house->name, 'operational_role' => $this->access->role($user, $house),
                'total_rooms' => $total, 'occupied_rooms' => $occupied, 'available_rooms' => $total - $occupied,
                'occupancy_rate' => $this->rate($occupied, $total),
                'outstanding_amount' => $this->money($houseInvoiceStats->get($house->id)?->outstanding_amount)];
        })->values();
        $totalRooms = (int) $houseData->sum('total_rooms');
        $occupiedRooms = (int) $houseData->sum('occupied_rooms');

        $recent = (clone $unpaid)->select(['invoices.id', 'room_id', 'contract_id', 'invoice_code', 'total_amount', 'paid_amount', 'due_date', 'status'])
            ->orderByRaw('CASE WHEN due_date < ? THEN 0 ELSE 1 END', [$today->toDateString()])
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')->orderBy('due_date')->orderByDesc('id')
            ->limit(self::RECENT_INVOICES)->with(['room:id,boarding_house_id,room_code', 'room.boardingHouse:id,name', 'contract:id,tenant_id,signing_snapshot', 'contract.tenant:id,full_name'])->get();
        $expiring = (clone $contracts)->where('end_date', '<=', $today->addDays(self::EXPIRING_DAYS)->toDateString())
            ->select(['id', 'room_id', 'tenant_id', 'contract_code', 'end_date', 'signing_snapshot'])
            ->orderBy('end_date')->orderBy('id')->limit(self::RECENT_CONTRACTS)->with(['room:id,room_code', 'tenant:id,full_name'])->get();

        return [
            'viewer' => ['name' => $user->name, 'can_create_boarding_house' => $user->landlord()->exists(),
                'owned_boarding_houses' => $houseData->where('operational_role', 'OWNER')->count(),
                'managed_boarding_houses' => $houseData->where('operational_role', 'MANAGER')->count()],
            'period' => ['month' => $start->format('Y-m'), 'label' => $start->format('m/Y'), 'today' => $today->toDateString(), 'timezone' => config('app.timezone')],
            'summary' => ['total_boarding_houses' => $houses->count(), 'total_rooms' => $totalRooms, 'occupied_rooms' => $occupiedRooms,
                'available_rooms' => $totalRooms - $occupiedRooms, 'occupancy_rate' => $this->rate($occupiedRooms, $totalRooms),
                'active_tenants' => $activeTenants, 'active_contracts' => (int) $contractStats->active_contracts,
                'expiring_window_days' => self::EXPIRING_DAYS,
                'expiring_soon_contracts' => (int) $contractStats->expiring_soon_contracts,
                'occupancy_status_mismatches' => (int) $roomStats->sum('occupancy_status_mismatches')],
            'invoice_summary' => ['invoice_count' => (int) $invoiceStats->invoice_count, 'invoice_total' => $this->money($invoiceStats->invoice_total),
                'paid_amount' => $this->money($invoiceStats->paid_amount), 'outstanding_amount' => $this->money($invoiceStats->outstanding_amount),
                'unpaid_invoice_count' => (int) $collectionStats->unpaid_invoice_count, 'unpaid_amount' => $this->money($collectionStats->unpaid_amount),
                'overdue_invoice_count' => (int) $collectionStats->overdue_invoice_count, 'overdue_amount' => $this->money($collectionStats->overdue_amount),
                'statuses' => ['PAID' => (int) $invoiceStats->paid_count, 'UNPAID' => (int) $invoiceStats->unpaid_count, 'PARTIALLY_PAID' => (int) $invoiceStats->partially_paid_count]],
            'revenue_chart' => $this->payments($user, $start), 'boarding_houses' => $houseData,
            'recent_unpaid_invoices' => $recent->map(fn (Invoice $invoice) => [
                'id' => $invoice->id, 'invoice_code' => $invoice->invoice_code, 'room_code' => $invoice->room->room_code,
                'boarding_house_name' => $invoice->room->boardingHouse->name,
                'tenant_name' => $invoice->contract?->signing_snapshot['tenant']['full_name'] ?? $invoice->contract?->tenant?->full_name,
                'remaining_amount' => (string) BigDecimal::of($invoice->total_amount)->minus($invoice->paid_amount),
                'due_date' => $invoice->due_date?->format('Y-m-d'), 'status' => $invoice->status,
                'is_overdue' => $invoice->due_date !== null && $invoice->due_date->format('Y-m-d') < $today->toDateString(),
            ])->values(),
            'expiring_contracts' => $expiring->map(fn (Contract $contract) => [
                'id' => $contract->id, 'contract_code' => $contract->contract_code, 'room_code' => $contract->room->room_code,
                'tenant_name' => $contract->signing_snapshot['tenant']['full_name'] ?? $contract->tenant?->full_name,
                'end_date' => $contract->end_date->format('Y-m-d'), 'days_remaining' => (int) $today->diffInDays($contract->end_date),
            ])->values(),
        ];
    }

    private function payments(User $user, CarbonImmutable $currentMonth): array
    {
        $first = $currentMonth->subMonths(5);
        $query = Payment::query()->whereHas('invoice', fn ($query) => $query->whereIn('room_id', $this->rooms($user)->select('rooms.id'))->whereIn('status', ['UNPAID', 'PARTIALLY_PAID', 'PAID']))
            ->where('paid_at', '>=', $first->format('Y-m-d H:i:s'))->where('paid_at', '<', $currentMonth->addMonth()->format('Y-m-d H:i:s'))->toBase();
        $months = [];
        for ($index = 0; $index < 6; $index++) {
            $month = $first->addMonths($index);
            $query->selectRaw("COALESCE(SUM(CASE WHEN paid_at >= ? AND paid_at < ? THEN amount ELSE 0 END), 0) AS month_{$index}", [$month->format('Y-m-d H:i:s'), $month->addMonth()->format('Y-m-d H:i:s')]);
            $months[] = $month;
        }
        $stats = $query->first();

        return array_map(fn ($month, $index) => ['month' => $month->format('Y-m'), 'label' => $month->format('m/Y'), 'amount' => $this->money($stats->{'month_'.$index})], $months, array_keys($months));
    }
}
