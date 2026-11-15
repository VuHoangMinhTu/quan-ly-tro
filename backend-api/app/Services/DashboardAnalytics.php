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
use Carbon\CarbonImmutable as Date;
use Illuminate\Database\Eloquent\Builder;

final class DashboardAnalytics
{
    private const INVOICES = ['UNPAID', 'PARTIALLY_PAID', 'PAID'];

    public function __construct(private BoardingHouseAccessService $access) {}

    private function houses(User $user, ?int $house): Builder
    {
        if ($house !== null) {
            $this->access->house($user, $house);
        }

        return $this->access->houses($user)->when($house !== null, fn ($query) => $query->whereKey($house));
    }

    private function rooms(Builder $houses): Builder
    {
        return Room::query()->whereIn('boarding_house_id', (clone $houses)->select('boarding_houses.id'));
    }

    public function snapshot(User $user, ?int $house, Date $now): array
    {
        $houses = $this->houses($user, $house);
        $rooms = $this->rooms($houses);
        $occupancy = (clone $rooms)->select(['id', 'boarding_house_id'])->withActiveOccupancy();
        $stats = $rooms->getQuery()->newQuery()->fromSub($occupancy->toBase(), 'occupancy')
            ->selectRaw('boarding_house_id, COUNT(*) AS total, SUM(CASE WHEN active_occupants_count > 0 THEN 1 ELSE 0 END) AS occupied')
            ->groupBy('boarding_house_id')->get()->keyBy('boarding_house_id');
        $breakdown = $houses->with('landlord')->orderBy('name')->orderBy('id')->get()->map(function ($row) use ($stats, $user) {
            $total = (int) ($stats->get($row->id)->total ?? 0);
            $occupied = (int) ($stats->get($row->id)->occupied ?? 0);

            return ['id' => $row->id, 'name' => $row->name, 'operational_role' => $this->access->role($user, $row),
                'total_rooms' => $total, 'occupied_rooms' => $occupied, 'occupancy_pct' => $this->rate($occupied, $total)];
        });
        $ids = (clone $rooms)->select('rooms.id');
        $today = $now->toDateString();
        $contracts = Contract::query()->whereIn('room_id', $ids)->where('status', 'ACTIVE')->where('is_demo', false)
            ->where('start_date', '<=', $today)
            ->where(fn ($query) => $query->whereNull('end_date')->orWhere('end_date', '>=', $today));
        $contracts->selectRaw('COUNT(*) AS active');
        $ranges = [[0, 7], [8, 14], [15, 21], [22, 30]];
        foreach ($ranges as $i => [$from, $to]) {
            $contracts->selectRaw("COUNT(CASE WHEN end_date BETWEEN ? AND ? THEN 1 END) AS range_$i", [
                $now->addDays($from)->toDateString(), $now->addDays($to)->toDateString(),
            ]);
        }
        $counts = $contracts->toBase()->first();
        $expiry = [];
        foreach ($ranges as $i => [$from, $to]) {
            $expiry[] = ['from_day' => $from, 'to_day' => $to, 'count' => (int) $counts->{"range_$i"}];
        }
        $total = $breakdown->sum('total_rooms');
        $occupied = $breakdown->sum('occupied_rooms');

        return [
            'as_of' => $now->toIso8601String(), 'timezone' => DashboardPeriod::ZONE, 'boarding_house_id' => $house,
            'rooms' => ['total' => $total, 'occupied' => $occupied, 'unoccupied' => $total - $occupied, 'occupancy_pct' => $this->rate($occupied, $total)],
            'contracts' => ['active' => (int) $counts->active, 'expiring_30d' => array_sum(array_column($expiry, 'count')), 'expiry_buckets' => $expiry],
            'tenants' => ['active' => RoomTenant::query()->whereIn('room_id', $ids)->where('status', 'ACTIVE')
                ->whereHas('tenant')->distinct()->count('tenant_id')],
            'houses' => $breakdown,
        ];
    }

    private function rate(int $occupied, int $total): string
    {
        return $total ? (string) BigDecimal::of($occupied * 100)->dividedBy($total, 2, RoundingMode::HalfUp) : '0.00';
    }

    public function analytics(User $user, array $query, Date $now): array
    {
        [$input, $start, $end, $group, $previous] = DashboardPeriod::resolve($query, $now->startOfDay());
        $house = isset($input['boarding_house_id']) ? (int) $input['boarding_house_id'] : null;
        $rooms = $this->rooms($this->houses($user, $house))->select('rooms.id');
        $invoices = Invoice::query()->whereIn('room_id', $rooms)->whereIn('status', self::INVOICES);
        $payments = Payment::query()->whereIn('invoice_id', (clone $invoices)->select('invoices.id'));
        $contracts = Contract::query()->whereIn('room_id', $rooms)->where('is_demo', false)
            ->whereIn('status', ['ACTIVE', 'EXPIRED', 'TERMINATED']);
        $sources = [
            [$payments, 'paid_at', ['cash_received' => 'amount']],
            [$invoices, 'billing_period', ['invoice_count' => '1', 'invoice_value' => 'total_amount',
                'invoice_paid_current' => 'paid_amount', 'invoice_due_current' => 'total_amount - paid_amount']],
            [$contracts, 'start_date', ['contracts_started' => '1']],
            [$contracts, 'end_date', ['contracts_ended' => '1']],
        ];
        $buckets = DashboardPeriod::buckets($start, $end, $group);
        [$totals, $series] = $this->aggregate($sources, $buckets);
        $comparison = null;
        if (($input['compare'] ?? '0') == '1') {
            [$previousTotals] = $this->aggregate($sources, [[$previous, $start]]);
            $comparison = [...$this->bounds($previous, $start), 'totals' => $previousTotals];
        }

        return [
            'as_of' => $now->toIso8601String(), 'timezone' => DashboardPeriod::ZONE, 'currency' => 'VND',
            'scope' => ['boarding_house_id' => $house],
            'period' => [...$this->bounds($start, $end), 'group_by' => $group],
            'availability' => ['cash' => 'supported', 'invoices' => 'supported', 'contracts' => 'supported',
                'tenant_movements' => 'unsupported', 'expenses' => 'unsupported', 'historical_occupancy' => 'unsupported', 'net_refunds' => 'unsupported'],
            'totals' => $totals, 'series' => $series, 'previous' => $comparison,
        ];
    }

    private function bounds(Date $start, Date $end): array
    {
        return ['from' => $start->toDateString(), 'to_exclusive' => $end->toDateString()];
    }

    private function aggregate(array $sources, array $buckets): array
    {
        $series = array_map(fn ($range) => $this->bounds(...$range), $buckets);
        $totals = [];
        foreach ($sources as [$source, $column, $metrics]) {
            // Aggregate bounded buckets in SQL, with one range predicate per source.
            $date = fn (Date $value) => $column === 'paid_at'
                ? $value->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s')
                : $value->toDateString();
            $query = (clone $source)->where($column, '>=', $date($buckets[0][0]))
                ->where($column, '<', $date($buckets[array_key_last($buckets)][1]));
            $storageZone = config('app.timezone');
            $grouped = $query->getConnection()->getDriverName() === 'mysql'
                && ($column !== 'paid_at' || in_array($storageZone, ['UTC', DashboardPeriod::ZONE], true))
                && count($buckets) > 1;
            if ($grouped) {
                $day = $column === 'paid_at' && $storageZone === 'UTC'
                    ? "DATE(DATE_ADD($column, INTERVAL 7 HOUR))"
                    : "DATE($column)";
                $query->selectRaw("$day AS bucket_day")->groupBy('bucket_day');
                $dayToBucket = [];
                foreach ($buckets as $i => [$from, $to]) {
                    for ($cursor = $from; $cursor < $to; $cursor = $cursor->addDay()) {
                        $dayToBucket[$cursor->toDateString()] = $i;
                    }
                }
            }
            foreach ($metrics as $name => $expression) {
                $totals[$name] = str_starts_with($name, 'contracts_') || $name === 'invoice_count' ? 0 : '0.00';
                if ($grouped) {
                    $query->selectRaw("SUM($expression) AS $name");

                    continue;
                }
                foreach ($buckets as $i => [$from, $to]) {
                    $query->selectRaw("SUM(CASE WHEN $column >= ? AND $column < ? THEN $expression ELSE 0 END) AS ${name}_$i", [$date($from), $date($to)]);
                }
            }
            $result = $grouped ? $query->toBase()->get() : $query->toBase()->first();
            foreach ($metrics as $name => $expression) {
                $values = [];
                if ($grouped) {
                    foreach ($result as $dayRow) {
                        $i = $dayToBucket[$dayRow->bucket_day];
                        $values[$i] = BigDecimal::of((string) ($dayRow->$name ?? 0))->plus($values[$i] ?? 0);
                    }
                }
                foreach ($buckets as $i => $_) {
                    $value = $grouped ? ($values[$i] ?? 0) : ($result->{$name.'_'.$i} ?? 0);
                    if (is_int($totals[$name])) {
                        $series[$i][$name] = (int) (string) $value;
                        $totals[$name] += $series[$i][$name];
                    } else {
                        $amount = BigDecimal::of((string) $value)->toScale(2, RoundingMode::HalfUp);
                        $series[$i][$name] = (string) $amount;
                        $totals[$name] = (string) $amount->plus($totals[$name]);
                    }
                }
            }
        }

        return [$totals, $series];
    }
}
