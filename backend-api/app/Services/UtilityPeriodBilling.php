<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Room;
use App\Models\Service;
use App\Models\UtilityMeter;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UtilityPeriodBilling
{
    public function __construct(private TieredUtilityAllocator $allocator, private ContractServiceTerms $pricing) {}

    public function issue(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice): void {
            $room = Room::query()->lockForUpdate()->findOrFail($invoice->room_id);
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            app(ContractSettlementPolicy::class)->assertInvoiceWriteAllowed($invoice);
            $this->require(DB::table('invoice_obligations')->where('invoice_id', $invoice->id)->exists(), 'invoice_id', 'Chỉ phát hành nghĩa vụ có bằng chứng V2.');
            if (in_array($invoice->status, ['UNPAID', 'PARTIALLY_PAID', 'PAID'], true) && $invoice->issued_at !== null) {
                return;
            }
            $this->require($invoice->status === 'DRAFT' && $invoice->issued_at === null && BigDecimal::of($invoice->paid_amount)->isZero() && ! $invoice->payments()->exists() && ! $invoice->payosPaymentRequests()->exists(), 'status', 'Chỉ phát hành hóa đơn V2 nháp chưa có giao dịch thanh toán.');
            app(MonthlyInvoiceBilling::class)->assertSourcesCurrent($invoice);
            $period = $invoice->billing_period->toDateString();
            $composition = DB::table('monthly_invoice_compositions')->where('invoice_id', $invoice->id)->first();
            $isBedRent = $room->room_type === 'DORMITORY' && $composition
                && (json_decode($composition->snapshot, true)['engine'] ?? null) === 'DORM_BED_V3';
            // Shared dorm meters are internal evidence, not a prerequisite to issue inclusive bed rent.
            $cycles = $isBedRent ? collect() : DB::table('utility_billing_periods')
                ->where('billing_period', $period)
                ->whereIn('utility_meter_id', $room->utilityMeters()->withTrashed()->select('id'))->get();
            $this->require($cycles->isNotEmpty() || $composition !== null, 'billing_period', 'Chưa có bằng chứng tính tiền V2.');
            $firstCycle = $cycles->first();
            if ($firstCycle === null) {
                $this->require($isBedRent || ! $room->utilityMeters()->where('is_active', true)->exists(),
                    'billing_period', 'Thiếu kỳ sử dụng đã xác nhận cho công tơ đang hoạt động.');
                $invoice->recalculateTotals();
                $invoice->update(['status' => 'UNPAID', 'issued_at' => now()->toDateString()]);

                return;
            }
            $this->require($cycles->pluck('utility_meter_id')->sort()->values()->all() === $this->metersForPeriod($room, $period,
                CarbonImmutable::parse($cycles->min('usage_start_at'), 'Asia/Ho_Chi_Minh'),
                CarbonImmutable::parse($cycles->max('usage_end_at'), 'Asia/Ho_Chi_Minh'))->pluck('id')->sort()->values()->all(), 'billing_period', 'Danh sách công tơ đã thay đổi. Cần tính lại đủ công tơ trước khi phát hành.');
            foreach ($cycles as $cycle) {
                $meter = UtilityMeter::withTrashed()->with(['service' => fn ($query) => $query->withTrashed()->with('priceTiers')])->findOrFail($cycle->utility_meter_id);
                // Issuance must recheck V1/V2 history, including cycles calculated before this patch.
                $this->assertLegacyMeterHistoryConsistent($meter);
                $current = $this->calculate($room, $meter,
                    CarbonImmutable::parse($cycle->usage_start_at, $cycle->timezone),
                    CarbonImmutable::parse($cycle->usage_end_at, $cycle->timezone));
                $this->require($current == json_decode($cycle->snapshot, true, 512, JSON_THROW_ON_ERROR), 'billing_period', 'Mốc chốt hoặc bảng giá đã thay đổi. Hãy tính lại hóa đơn nháp và kiểm tra trước khi phát hành.');
            }
            $invoice->recalculateTotals();
            $invoice->update(['status' => 'UNPAID', 'issued_at' => now()->toDateString()]);
        }, 3);
    }

    /** Fail closed if the same physical meter has inconsistent V1 and V2 evidence. */
    private function assertLegacyMeterHistoryConsistent(UtilityMeter $meter): void
    {
        $legacy = $meter->readings()->get(['reading_date', 'reading_value']);
        if ($legacy->isEmpty()) return;
        $effective = DB::table('utility_checkpoints')->where('utility_meter_id', $meter->id)
            ->orderByDesc('revision')->orderByDesc('id')->get()->unique('scope_key');
        foreach ($legacy as $reading) {
            $date = $reading->reading_date->format('Y-m-d');
            foreach ($effective as $point) {
                $pointDate = substr($point->observed_at, 0, 10);
                $compare = BigDecimal::of((string) $point->reading_value)->compareTo((string) $reading->reading_value);
                $inconsistent = ($pointDate > $date && $compare < 0)
                    || ($pointDate < $date && $compare > 0);
                // V1 has no time-of-day; same-day different measurements cannot
                // be attributed safely to a specific settlement boundary.
                $ambiguous = $pointDate === $date && $compare !== 0;
                $this->require(! $inconsistent && ! $ambiguous, 'utility_meter_history', 'Công tơ #'.$meter->id.' có lịch sử chỉ số V1/V2 mâu thuẫn hoặc không rõ giờ trong cùng ngày. Phải đối soát trước khi tính hóa đơn; không tự bỏ qua dữ liệu cũ.');
            }
        }
    }

    public function generate(Room $room, string $period, string $usageStartAt, string $usageEndAt, int $actor, array $meterPeriods = []): array
    {
        return $this->generateDrafts($room, $period, $usageStartAt, $usageEndAt, $actor, false, $meterPeriods);
    }

    public function rebuildMonthlyDrafts(Room $room, string $period, string $usageStartAt, string $usageEndAt, int $actor, array $meterPeriods = []): array
    {
        return $this->generateDrafts($room, $period, $usageStartAt, $usageEndAt, $actor, true, $meterPeriods);
    }

    private function generateDrafts(Room $room, string $period, string $usageStartAt, string $usageEndAt, int $actor, bool $monthlyUpdate, array $meterPeriods): array
    {
        return DB::transaction(function () use ($room, $period, $usageStartAt, $usageEndAt, $actor, $monthlyUpdate, $meterPeriods): array {
            $room = Room::query()->lockForUpdate()->findOrFail($room->id);
            $this->require($room->room_type === 'PRIVATE_ROOM', 'room_id', 'V2 hiện chỉ phân bổ công tơ phòng riêng; công tơ dùng chung KTX cần chính sách phân bổ riêng.');
            $period = CarbonImmutable::parse($period)->startOfMonth()->toDateString();
            $start = CarbonImmutable::parse($usageStartAt, 'Asia/Ho_Chi_Minh');
            $end = CarbonImmutable::parse($usageEndAt, 'Asia/Ho_Chi_Minh');
            $this->require($start < $end, 'usage_end_at', 'Thời điểm kết thúc phải sau thời điểm bắt đầu kỳ sử dụng.');
            $this->require($monthlyUpdate || ! DB::table('monthly_invoice_compositions')->whereIn('invoice_id', $room->invoices()->select('id'))->where('billing_period', $period)->exists(), 'invoice_review', 'Kỳ đã tổng hợp hóa đơn tháng. Không tính lại riêng điện nước làm mất khoản phòng/dịch vụ; cần đối soát.');
            $existingCycles = DB::table('utility_billing_periods')->whereIn('utility_meter_id', $room->utilityMeters()->withTrashed()->select('id'))->where('billing_period', $period)->get();
            $meters = $this->metersForPeriod($room, $period, $start, $end);
            $windows = $this->resolveMeterWindows($meters, $existingCycles, $start, $end, $monthlyUpdate, $meterPeriods);
            if ($existingCycles->isNotEmpty() && ! $monthlyUpdate) {
                $sameWindow = $existingCycles->count() === $meters->count() && $existingCycles->every(function ($cycle) use ($windows) {
                    $window = $windows[(int) $cycle->utility_meter_id] ?? null;
                    return $window && $cycle->usage_start_at === $window[0]->format('Y-m-d H:i:s')
                        && $cycle->usage_end_at === $window[1]->format('Y-m-d H:i:s');
                });
                $this->require($sameWindow, 'usage_window', 'Tháng hóa đơn đã gắn với kỳ sử dụng khác. Dùng cập nhật tính toán nháp có preview để thay đổi kỳ.');

                return ['invoices' => Invoice::query()->whereIn('id', DB::table('invoice_obligations')->where('room_id', $room->id)->where('billing_period', $period)->pluck('invoice_id'))->with('items')->get()->all(),
                    'meters' => $existingCycles->map(fn ($cycle) => json_decode($cycle->snapshot, true, 512, JSON_THROW_ON_ERROR))->all(), 'replayed' => true];
            }
            app(ContractSettlementPolicy::class)->assertRoomReviewComplete($room, $period);
            $obligations = DB::table('invoice_obligations')->where('room_id', $room->id)->where('billing_period', $period)->get();
            $invoices = Invoice::withTrashed()->whereIn('id', $obligations->pluck('invoice_id'))->lockForUpdate()->get();
            foreach ($invoices as $invoice) {
                $this->require($invoice->status === 'DRAFT' && $invoice->issued_at === null && BigDecimal::of($invoice->paid_amount)->isZero() && $invoice->deleted_at === null && ! $invoice->payments()->exists() && ! $invoice->payosPaymentRequests()->exists(), 'billing_period', 'Kỳ có hóa đơn đã phát hành/thanh toán hoặc chứng từ thanh toán; không tự tính lại.');
            }
            $this->require(! Invoice::withTrashed()->where('room_id', $room->id)->whereDate('billing_period', $period)->where('status', '!=', 'CANCELLED')->whereNotIn('id', $obligations->pluck('invoice_id'))->exists(), 'billing_period', 'Kỳ có hóa đơn cũ chưa có bằng chứng phân bổ V2. Cần đối soát thủ công trước để tránh thu trùng.');
            $calculations = [];
            foreach ($meters as $meter) {
                [$meterStart, $meterEnd] = $windows[(int) $meter->id];
                UtilityMeter::withTrashed()->lockForUpdate()->findOrFail($meter->id);
                $this->assertLegacyMeterHistoryConsistent($meter);
                $overlap = DB::table('utility_billing_periods')->where('utility_meter_id', $meter->id)
                    ->when($monthlyUpdate, fn ($query) => $query->where('billing_period', '!=', $period))
                    ->where('usage_start_at', '<', $meterEnd->format('Y-m-d H:i:s'))
                    ->where('usage_end_at', '>', $meterStart->format('Y-m-d H:i:s'))->exists();
                $this->require(! $overlap, 'usage_window', 'Kỳ sử dụng chồng lấn với kỳ đã ghi nhận hoặc đã gắn hóa đơn khác. Cần đối soát trước khi tính.');
                $calculations[] = $this->calculate($room, $meter, $meterStart, $meterEnd);
            }
            $this->require($calculations !== [], 'utility_meter_id', 'Phòng chưa có công tơ hợp lệ.');
            $periodIds = DB::table('utility_billing_periods')->whereIn('utility_meter_id', $room->utilityMeters()->withTrashed()->select('id'))->where('billing_period', $period)->pluck('id');
            $this->require(! DB::table('utility_charge_allocations')->whereIn('utility_billing_period_id', $periodIds)->whereNotNull('supplier_cost')->exists(), 'billing_period', 'Kỳ đã ghi giá vốn thực tế; cần đối soát thay vì ghi đè phân bổ.');
            DB::table('utility_charge_allocations')->whereIn('utility_billing_period_id', $periodIds)->delete();
            DB::table('utility_billing_periods')->whereIn('id', $periodIds)->delete();
            foreach ($invoices as $invoice) {
                $invoice->items()->where('source', 'AUTO')->delete();
            }
            foreach ($calculations as $calculation) {
                $cycle = DB::table('utility_billing_periods')->insertGetId([
                    'utility_meter_id' => $calculation['meter_id'], 'billing_period' => $period, 'timezone' => 'Asia/Ho_Chi_Minh',
                    'usage_start_at' => $calculation['usage_start_at'], 'usage_end_at' => $calculation['usage_end_at'],
                    'start_checkpoint_id' => $calculation['start_checkpoint_id'], 'end_checkpoint_id' => $calculation['end_checkpoint_id'],
                    'start_confirmed_by' => $calculation['start_checkpoint']['confirmed_by'], 'end_confirmed_by' => $calculation['end_checkpoint']['confirmed_by'],
                    'start_confirmed_at' => $calculation['start_checkpoint']['confirmed_at'], 'end_confirmed_at' => $calculation['end_checkpoint']['confirmed_at'],
                    'calculated_by' => $actor, 'calculated_at' => now(), 'quantity' => $calculation['quantity'], 'tariff_total' => $calculation['total'],
                    'snapshot' => json_encode($calculation, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($calculation['segments'] as $index => $segment) {
                    $itemId = null;
                    if ($segment['responsible_party'] === 'TENANT' && BigDecimal::of($segment['quantity'])->isPositive()) {
                        $contractId = $segment['contract_id'];
                        $obligation = $obligations->firstWhere('contract_id', $contractId);
                        $invoice = $obligation ? $invoices->firstWhere('id', $obligation->invoice_id) : null;
                        if (! $invoice) {
                            $invoice = $room->invoices()->create(['contract_id' => $contractId, 'billing_period' => $period, 'status' => 'DRAFT', 'discount_amount' => 0, 'note' => 'Quyết toán điện nước V2; không tự tính lại tiền phòng hoặc dịch vụ cố định.']);
                            DB::table('invoice_obligations')->insert(['room_id' => $room->id, 'contract_id' => $contractId, 'billing_period' => $period, 'kind' => 'UTILITY_V2', 'invoice_id' => $invoice->id]);
                            $invoices->push($invoice);
                            $obligations = DB::table('invoice_obligations')->where('room_id', $room->id)->where('billing_period', $period)->get();
                        }
                        $itemId = $invoice->items()->create(['type' => $calculation['type'], 'description' => $calculation['name'].' · '.$segment['quantity'].' '.$calculation['unit'],
                            'quantity' => $segment['quantity'], 'unit' => $calculation['unit'], 'unit_price' => $calculation['method'] === 'PER_UNIT' ? $calculation['price']['base_price'] : 0,
                            'amount' => $segment['tariff_amount'], 'source' => 'AUTO'])->id;
                    }
                    DB::table('utility_charge_allocations')->insert(['utility_billing_period_id' => $cycle, 'segment_order' => $index, ...$segment, 'supplier_cost' => null, 'invoice_item_id' => $itemId]);
                }
            }
            foreach ($invoices as $invoice) {
                $invoice->recalculateTotals();
            }
            app(ContractSettlementPolicy::class)->reconcile($room, $period, $start, $end, $calculations);

            return ['invoices' => $invoices->map(fn (Invoice $invoice) => $invoice->fresh()->load('items'))->all(), 'meters' => $calculations];
        }, 3);
    }

    /**
     * A billing month is a grouping key, NOT a requirement that every meter has
     * the same physical reading times. Explicit windows are scoped to meter IDs.
     * A previously calculated mixed window may be replayed and rebuilt safely.
     */
    private function resolveMeterWindows(Collection $meters, $existingCycles, CarbonImmutable $start, CarbonImmutable $end, bool $monthlyUpdate, array $meterPeriods): array
    {
        $ids = $meters->pluck('id')->map(fn ($id) => (int) $id)->all();
        $existing = $existingCycles->keyBy('utility_meter_id');
        $mixedExisting = $existingCycles->map(fn ($cycle) => $cycle->usage_start_at.'|'.$cycle->usage_end_at)->unique()->count() > 1;
        $this->require(count($meterPeriods) === count(array_unique(array_map(fn ($row) => (int) ($row['utility_meter_id'] ?? 0), $meterPeriods))), 'meter_periods', 'Một đồng hồ chỉ được khai báo một kỳ trong một lần quyết toán.');
        foreach ($meterPeriods as $row) {
            $this->require(in_array((int) $row['utility_meter_id'], $ids, true), 'meter_periods', 'Công tơ không thuộc phòng hoặc không thuộc kỳ được chọn.');
        }
        $specified = collect($meterPeriods)->keyBy('utility_meter_id');
        $windows = [];
        foreach ($meters as $meter) {
            $row = $specified->get($meter->id);
            $fromExisting = false;
            if ($row) {
                $meterStart = CarbonImmutable::parse($row['usage_start_at'], 'Asia/Ho_Chi_Minh');
                $meterEnd = CarbonImmutable::parse($row['usage_end_at'], 'Asia/Ho_Chi_Minh');
            } elseif ($existing->has($meter->id) && $mixedExisting) {
                $fromExisting = true;
                $cycle = $existing->get($meter->id);
                $meterStart = CarbonImmutable::parse($cycle->usage_start_at, $cycle->timezone);
                $meterEnd = CarbonImmutable::parse($cycle->usage_end_at, $cycle->timezone);
            } else {
                $this->require(!$specified->isNotEmpty(), 'meter_periods', 'Đã gửi kỳ riêng nhưng thiếu công tơ #'.$meter->id.'.');
                $meterStart = $start;
                $meterEnd = $end;
            }
            $this->require($meterStart < $meterEnd, 'meter_periods', 'Mốc cuối phải sau mốc đầu của công tơ #'.$meter->id.'.');
            if (! $fromExisting) {
                $this->require($meterStart >= $start && $meterEnd <= $end, 'meter_periods', 'Kỳ công tơ #'.$meter->id.' nằm ngoài khoảng bao phủ đã chọn.');
            }
            $windows[(int) $meter->id] = [$meterStart, $meterEnd];
        }

        return $windows;
    }

    private function metersForPeriod(Room $room, string $period, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return $room->utilityMeters()->withTrashed()->where(function ($query) use ($period, $start, $end): void {
            $query->where('is_active', true)->orWhereIn('id', DB::table('utility_billing_periods')->where('billing_period', $period)->select('utility_meter_id'))
                ->orWhereIn('id', DB::table('utility_checkpoints')->where('observed_at', '>=', $start->format('Y-m-d H:i:s'))->where('observed_at', '<=', $end->format('Y-m-d H:i:s'))->select('utility_meter_id'));
        })->with(['service' => fn ($query) => $query->withTrashed()->with('priceTiers')])->orderBy('id')->get();
    }

    private function calculate(Room $room, UtilityMeter $meter, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $service = $meter->service;
        $this->require($service !== null && (int) $service->boarding_house_id === (int) $room->boarding_house_id && in_array($service->type, ['ELECTRICITY', 'WATER'], true), 'utility_meter_id', 'Công tơ/dịch vụ không thuộc đúng nhà trọ.');
        $points = DB::table('utility_checkpoints')->where('utility_meter_id', $meter->id)->orderByDesc('revision')->get()->unique('scope_key')->values();
        $first = $points->first(fn ($point) => $point->kind === 'PERIOD_BOUNDARY' && $point->observed_at === $start->format('Y-m-d H:i:s'));
        $last = $points->first(fn ($point) => $point->kind === 'PERIOD_BOUNDARY' && $point->observed_at === $end->format('Y-m-d H:i:s'));
        $meterName = $meter->meter_code ?: '#'.$meter->id;
        $this->require($first !== null, 'checkpoints', "Công tơ {$meterName}: chưa có mốc ĐẦU đúng {$start->format('d/m/Y H:i:s')}. Hãy kiểm tra thời điểm đã lưu.");
        $this->require($last !== null, 'checkpoints', "Công tơ {$meterName}: chưa có mốc CUỐI đúng {$end->format('d/m/Y H:i:s')}. Hãy kiểm tra thời điểm đã lưu.");
        $intervals = [];
        $prices = [];
        foreach ($room->contracts()->whereIn('status', ['ACTIVE', 'EXPIRED', 'TERMINATED'])->whereDate('start_date', '<', $end->toDateString())->get() as $contract) {
            $termination = DB::table('contract_terminations')->where('contract_id', $contract->id)->first();
            if ($termination && $termination->actual_ended_at <= $start->format('Y-m-d H:i:s')) {
                continue;
            }
            $this->require((int) $contract->tenant?->landlord_id === (int) $room->boardingHouse->landlord_id, 'contract_id', 'Hợp đồng/người thuê không thuộc đúng chủ trọ.');
            $in = $points->first(fn ($point) => $point->kind === 'CHECK_IN' && (int) $point->contract_id === $contract->id);
            $out = $points->first(fn ($point) => $point->kind === 'CHECK_OUT' && (int) $point->contract_id === $contract->id);
            $this->require($in !== null, 'checkpoints', 'Thiếu CHECK_IN đã xác nhận của hợp đồng; không dùng chỉ số người thuê cũ.');
            if ($in->observed_at >= $end->format('Y-m-d H:i:s')) {
                continue;
            }
            $this->require($contract->status === 'ACTIVE' || ($termination !== null && $out !== null), 'checkpoints', 'Hợp đồng đã kết thúc nhưng chưa có lý do và CHECK_OUT xác nhận.');
            $this->require($out !== null || ! $contract->occupants()->where('tenant_id', $contract->tenant_id)->where('is_primary', true)->where('status', 'MOVED_OUT')->whereDate('move_out_date', '>=', substr($in->observed_at, 0, 10))->whereDate('move_out_date', '<', $end->toDateString())->exists(), 'checkpoints', 'Người đứng tên đã chuyển đi nhưng chưa xác nhận CHECK_OUT thực tế.');
            $this->require(! $termination || ($out !== null && $out->observed_at === $termination->actual_ended_at), 'checkpoints', 'Thời điểm CHECK_OUT không khớp kết thúc thực tế.');
            $this->require(! $out || $out->observed_at >= $in->observed_at, 'checkpoints', 'CHECK_OUT phải sau CHECK_IN.');
            $intervals[] = ['contract' => $contract, 'start' => $in->observed_at, 'end' => $out?->observed_at ?? '9999-12-31 23:59:59',
                'abandoned' => app(ContractSettlementPolicy::class)->abandonedWithin($contract->id, $start, $end)];
            $this->require($contract->handoverReports()->where('type', 'CHECK_IN')->where('status', 'CONFIRMED')->exists(), 'contract_id', 'Hợp đồng chưa có biên bản nhận phòng được xác nhận.');
            foreach ($this->prices($contract, $service, CarbonImmutable::parse(max($in->observed_at, $start->format('Y-m-d H:i:s'))), CarbonImmutable::parse(min($out?->observed_at ?? $end->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')))) as $price) {
                $prices[json_encode($price, JSON_THROW_ON_ERROR)] = $price;
            }
        }
        $this->require(count($prices) <= 1, 'tariff_conflict', 'Các hợp đồng trong cùng công tơ/kỳ có bảng giá khác nhau. Chủ trọ cần xác nhận bảng giá theo kỳ; hệ thống không tự chọn.');
        $price = $prices === [] ? $this->normalizePrice($service->toArray() + ['pricing_snapshot' => $service->priceTiers->toArray()]) : reset($prices);
        $this->require($price['service_type'] === $service->type && $price['unit'] === $service->unit, 'tariff_conflict', 'Loại hoặc đơn vị dịch vụ trên thỏa thuận không khớp công tơ; cần đối soát, không tự quy đổi.');
        $timeline = $points->filter(fn ($point) => $point->observed_at >= $first->observed_at && $point->observed_at <= $last->observed_at)->sortBy('observed_at')->groupBy('observed_at');
        $ordered = [];
        foreach ($timeline as $sameTime) {
            $this->require($sameTime->map(fn ($point) => (string) BigDecimal::of($point->reading_value)->strippedOfTrailingZeros())->unique()->count() === 1, 'checkpoints', 'Các mốc cùng thời điểm có chỉ số khác nhau.');
            $ordered[] = $sameTime->sortBy('id')->first();
        }
        $segments = [];
        foreach ($ordered as $index => $point) {
            if ($index === 0) {
                continue;
            }
            $previous = $ordered[$index - 1];
            $usage = BigDecimal::of($point->reading_value)->minus($previous->reading_value);
            $this->require(! $usage->isNegative(), 'checkpoints', 'Chỉ số chốt giảm theo thời gian.');
            $responsible = array_values(array_filter($intervals, fn ($interval) => $interval['start'] <= $previous->observed_at && $interval['end'] >= $point->observed_at));
            $this->require(count($responsible) <= 1, 'contract_id', 'Khoảng cư trú chồng lấn; cần đối soát trước khi phân bổ.');
            $interval = $responsible[0] ?? null;
            $segments[] = ['contract_id' => $interval ? $interval['contract']->id : null, 'from_checkpoint_id' => $previous->id, 'to_checkpoint_id' => $point->id,
                'quantity' => (string) $usage, 'responsible_party' => $interval && ! $interval['abandoned'] ? 'TENANT' : 'LANDLORD',
                'reason' => ! $interval ? 'VACANT' : ($interval['abandoned'] ? 'ABANDONMENT' : 'OCCUPIED')];
        }
        $quantity = BigDecimal::of($last->reading_value)->minus($first->reading_value);
        $amount = BigDecimal::zero();
        if ($price['billing_method'] === 'PER_UNIT') {
            $amount = $quantity->multipliedBy($price['base_price']);
        } else {
            foreach ($price['pricing_snapshot'] as $tier) {
                $upper = $tier['to_quantity'] === null ? $quantity : BigDecimal::min($quantity, $tier['to_quantity']);
                $width = $upper->minus($tier['from_quantity']);
                if ($width->isPositive()) {
                    $amount = $amount->plus($width->multipliedBy($tier['unit_price']));
                }
            }
        }
        $total = (string) $amount->toScale(0, RoundingMode::HalfUp);
        $this->require(BigDecimal::of($total)->isLessThanOrEqualTo('9999999999999'), 'tariff_total', 'Tổng tiền điện nước vượt giới hạn tiền tệ của hóa đơn.');
        $shares = $this->allocator->allocate((string) $quantity, $total, array_column($segments, 'quantity'), 0);
        foreach ($segments as $index => &$segment) {
            $segment['tariff_amount'] = $shares[$index];
        }
        unset($segment);

        return ['engine_version' => 2, 'meter_id' => $meter->id, 'usage_start_at' => $start->format('Y-m-d H:i:s'), 'usage_end_at' => $end->format('Y-m-d H:i:s'),
            'start_checkpoint_id' => $first->id, 'end_checkpoint_id' => $last->id,
            'start_checkpoint' => ['id' => $first->id, 'observed_at' => $first->observed_at, 'reading_value' => $first->reading_value, 'confirmed_by' => $first->confirmed_by, 'confirmed_at' => $first->created_at],
            'end_checkpoint' => ['id' => $last->id, 'observed_at' => $last->observed_at, 'reading_value' => $last->reading_value, 'confirmed_by' => $last->confirmed_by, 'confirmed_at' => $last->created_at],
            'type' => $service->type, 'name' => $service->name, 'unit' => $service->unit, 'method' => $price['billing_method'],
            'quantity' => (string) $quantity->toScale(2), 'total' => $total, 'price' => $price, 'segments' => $segments];
    }

    private function prices(Contract $contract, Service $service, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($contract->service_terms_version === null) {
            $this->require(false, 'tariff_conflict', 'Hợp đồng chưa có bảng giá thỏa thuận được lưu. Cần xác nhận chính sách giá theo kỳ; không tự dùng giá hiện tại cho dữ liệu cũ.');
        }
        $terms = $contract->agreedServices()->where('service_id', $service->id)->where('is_active', true)
            ->whereDate('effective_from', '<=', $end->toDateString())->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start->toDateString()))->orderBy('effective_from')->orderBy('id')->get()
            ->filter(fn ($term) => CarbonImmutable::parse($term->effective_from)->startOfDay() < $end && ($term->effective_to === null || CarbonImmutable::parse($term->effective_to)->addDay()->startOfDay() > $start));
        $this->require($terms->isNotEmpty(), 'services', 'Thiếu bảng giá đã thỏa thuận cho công tơ trong kỳ.');
        $covered = $start;
        foreach ($terms as $term) {
            $from = CarbonImmutable::parse($term->effective_from)->startOfDay();
            $to = $term->effective_to === null ? $end : CarbonImmutable::parse($term->effective_to)->addDay()->startOfDay();
            $this->require($from <= $covered && ($covered === $start || $from >= $covered), 'tariff_conflict', 'Khoảng hiệu lực bảng giá bị thiếu hoặc chồng lấn; cần xác nhận chính sách theo kỳ.');
            $covered = $to < $end ? $to : $end;
        }
        $this->require($covered >= $end, 'tariff_conflict', 'Bảng giá không bao phủ đủ khoảng tiêu thụ thực tế của hợp đồng.');

        return $terms->map(fn ($term) => $this->normalizePrice($term->toArray()))->all();
    }

    private function normalizePrice(array $data): array
    {
        $this->require(in_array($data['billing_method'], ['PER_UNIT', 'TIERED'], true), 'services', 'Công tơ chỉ áp dụng PER_UNIT hoặc TIERED.');
        $this->pricing->validatePricing($data);
        $this->require($data['billing_method'] !== 'PER_UNIT' || ! BigDecimal::of($data['base_price'])->isNegative(), 'services', 'Đơn giá không được âm.');
        $price = ['billing_method' => $data['billing_method'], 'service_type' => $data['service_type'] ?? $data['type'], 'unit' => $data['unit'] ?? null, 'base_price' => $data['billing_method'] === 'PER_UNIT' ? (string) BigDecimal::of($data['base_price'])->strippedOfTrailingZeros() : null, 'vat_rate' => (string) BigDecimal::of($data['vat_rate'] ?? 0)->strippedOfTrailingZeros(), 'pricing_snapshot' => []];
        foreach ($data['billing_method'] === 'TIERED' ? $data['pricing_snapshot'] : [] as $tier) {
            $this->require(! BigDecimal::of($tier['unit_price'])->isNegative(), 'services', 'Giá bậc thang không được âm.');
            $price['pricing_snapshot'][] = ['from_quantity' => (string) BigDecimal::of($tier['from_quantity'])->strippedOfTrailingZeros(), 'to_quantity' => $tier['to_quantity'] === null ? null : (string) BigDecimal::of($tier['to_quantity'])->strippedOfTrailingZeros(), 'unit_price' => (string) BigDecimal::of($tier['unit_price'])->strippedOfTrailingZeros()];
        }

        return $price;
    }

    private function require(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
