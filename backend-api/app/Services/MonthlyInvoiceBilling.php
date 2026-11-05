<?php

namespace App\Services;

use App\Models\BoardingHouse;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Room;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MonthlyInvoiceBilling
{
    public function generate(BoardingHouse $house, string $period, string $usageStartAt, string $usageEndAt, array $confirmations, int $actor): array
    {
        $results = [];
        foreach ($house->rooms()->orderBy('id')->get() as $room) {
            try {
                $results[] = DB::transaction(fn () => $this->room($room, $period, $usageStartAt, $usageEndAt, $confirmations, $actor), 3);
            } catch (ValidationException $error) {
                $results[] = ['room_id' => $room->id, 'room_code' => $room->room_code, 'status' => isset($error->errors()['checkpoints']) ? 'PENDING_FINAL_SETTLEMENT' : 'BLOCKED', 'errors' => $error->errors(), 'invoices' => []];
            }
        }

        return $results;
    }

    /** Separate transaction for one room; never touch other rooms. */
    public function generateForRoom(Room $room, string $period, string $usageStartAt, string $usageEndAt, array $confirmations, int $actor): array
    {
        try {
            return DB::transaction(fn () => $this->room($room, $period, $usageStartAt, $usageEndAt, $confirmations, $actor), 3);
        } catch (ValidationException $error) {
            return ['room_id' => $room->id, 'room_code' => $room->room_code,
                'status' => isset($error->errors()['checkpoints']) ? 'PENDING_FINAL_SETTLEMENT' : 'BLOCKED',
                'errors' => $error->errors(), 'invoices' => []];
        }
    }

    /** Only the preview/confirmation coordinator calls this under its room lock. */
    public function rebuildDrafts(Room $room, string $period, string $usageStartAt, string $usageEndAt, array $confirmations, int $actor): array
    {
        return $this->room($room, $period, $usageStartAt, $usageEndAt, $confirmations, $actor, true);
    }

    private function room(Room $room, string $period, string $usageStartAt, string $usageEndAt, array $confirmations, int $actor, bool $updating = false): array
    {
        $room = Room::query()->lockForUpdate()->findOrFail($room->id);
        $this->check(in_array($room->room_type, ['PRIVATE_ROOM', 'DORMITORY'], true), 'room_type', 'Loại phòng chưa được hỗ trợ bởi Billing V2.');
        $dormitory = $room->room_type === 'DORMITORY';
        $start = CarbonImmutable::parse($period);
        $end = $start->addMonth();
        $usageStart = CarbonImmutable::parse($usageStartAt, 'Asia/Ho_Chi_Minh');
        $usageEnd = CarbonImmutable::parse($usageEndAt, 'Asia/Ho_Chi_Minh');
        $this->check($usageStart < $usageEnd, 'usage_window', 'Thời điểm kết thúc phải sau thời điểm bắt đầu kỳ sử dụng.');
        // An invoice labelled December may use a meter window starting in November.
        // That window allocates utilities, not November rent. Preserve the existing
        // calendar-month rent behavior in that case; a cycle starting within the
        // invoice month instead follows the contract anniversary (e.g. Nov 4-Dec 4).
        $calendarRent = $dormitory || $usageStart->lt($start);
        $rentStart = $calendarRent ? $start : $usageStart;
        $rentEnd = $calendarRent ? $end : $usageEnd;
        $contracts = $this->contractsForPeriod($room, $rentStart, $rentEnd);
        $base = ['room_id' => $room->id, 'room_code' => $room->room_code];
        app(ContractSettlementPolicy::class)->assertRoomReviewComplete($room, $period);
        // KTX bed rent is all-inclusive by default: even an active shared meter is
        // internal evidence, NOT an automatic tenant receivable or prerequisite.
        $hasMeters = ! $dormitory && ($room->utilityMeters()->where('is_active', true)->exists() || DB::table('utility_billing_periods')->whereIn('utility_meter_id', $room->utilityMeters()->withTrashed()->select('id'))->where('billing_period', $period)->exists());
        if ($dormitory) {
            $this->check(! DB::table('utility_billing_periods')->whereIn('utility_meter_id', $room->utilityMeters()->withTrashed()->select('id'))->where('billing_period', $period)->exists(), 'invoice_review', 'KTX đã có chứng cứ tính điện nước V2 trong kỳ; cần đối soát trước khi tạo hóa đơn trọn gói.');
        }
        if ($contracts->isEmpty() && ! $hasMeters) {
            return [...$base, 'status' => 'NO_ELIGIBLE_CONTRACT', 'invoices' => []];
        }
        $existing = Invoice::withTrashed()->where('room_id', $room->id)->whereDate('billing_period', $period)->orderBy('id')->lockForUpdate()->get();
        $records = DB::table('monthly_invoice_compositions')->whereIn('invoice_id', $existing->pluck('id'))->get();
        $this->check(! $updating || $records->isNotEmpty(), 'invoice_review', 'Chỉ cập nhật draft đã có snapshot hóa đơn tháng.');
        if ($records->isNotEmpty()) {
            $expectedContracts = $contracts->reject(fn ($c) => $this->isAbandoned($c, $period, $usageStart, $usageEnd, $dormitory))->pluck('id')->sort()->values()->all();
            $this->check($expectedContracts === $records->pluck('contract_id')->map(fn ($id) => (int) $id)->sort()->values()->all(), 'invoice_review', 'Danh sách hợp đồng trong kỳ đã thay đổi; cần đối soát trước khi tạo thêm.');
            $composedIds = $records->pluck('invoice_id')->map(fn ($id) => (int) $id)->all();
            $utilityOnly = $existing->filter(fn (Invoice $invoice) => ! in_array((int) $invoice->id, $composedIds, true));
            $this->check($utilityOnly->every(fn (Invoice $invoice) => $this->isUtilityOnlyInvoice($invoice)), 'invoice_review', 'Kỳ có hóa đơn legacy/chưa đối soát. Không ghi đè.');
            $this->check($existing->every(fn ($i) => ! $i->deleted_at && $i->status === 'DRAFT' && ! $i->issued_at && BigDecimal::of($i->paid_amount)->isZero() && ! $i->payments()->exists() && ! $i->payosPaymentRequests()->exists()), 'invoice_review', 'Kỳ có hóa đơn đã phát hành/thanh toán hoặc chưa đối soát. Không ghi đè.');
            foreach ($records as $record) {
                if ($updating) {
                    $source = json_decode($record->snapshot, true);
                    $originalTerms = collect($source['sources']['terms'] ?? [])->pluck('id');
                    $currentTerms = Contract::findOrFail($record->contract_id)->agreedServices()->pluck('id');
                    $this->check($originalTerms->diff($currentTerms)->isEmpty(), 'agreement', 'Điều khoản nguồn đã mất; cần đối soát lịch sử thay vì tự bỏ khoản thu.');
                }
                $input = collect($confirmations)->firstWhere('contract_id', $record->contract_id);
                if ($updating && $input === null && json_decode($record->confirmation, true) !== []) {
                    $confirmations[] = json_decode($record->confirmation, true);
                }
                $this->check($updating || $input === null || $input == json_decode($record->confirmation, true), 'confirmations', 'Hóa đơn đã có xác nhận khác; cần quy trình điều chỉnh.');
            }

            if (! $updating) {
                foreach ($existing as $invoice) {
                    $record = $records->firstWhere('invoice_id', $invoice->id);
                    if ($record) {
                        $invoice->setAttribute('monthly_confirmation', json_decode($record->confirmation, true));
                    }
                }

                return [...$base, 'status' => 'EXISTING', 'invoices' => $existing->load('items')->all()];
            }
        }
        $plans = [];
        foreach ($contracts as $c) {
            $this->check((int) $c->tenant?->landlord_id === (int) $room->boardingHouse->landlord_id, 'contract_id', 'Người thuê không thuộc chủ trọ.');
            if ($this->isAbandoned($c, $period, $usageStart, $usageEnd, $dormitory)) {
                continue;
            }
            $this->check($c->handoverReports()->where('type', 'CHECK_IN')->where('status', 'CONFIRMED')->exists(), 'contract_id', 'Chưa có bàn giao nhận phòng được xác nhận.');
            $termination = DB::table('contract_terminations')->where('contract_id', $c->id)->first();
            $this->check($c->status === 'ACTIVE' || $termination !== null, 'contract_id', 'Thiếu ngày kết thúc thực tế.');
            $input = collect($confirmations)->firstWhere('contract_id', $c->id);
            $partial = $this->partialPeriod($c, $contracts, $start, $end, $termination, $dormitory, $usageStart, $usageEnd, $calendarRent);
            $plans[$c->id] = ['lines' => $this->lines($c, $room, $rentStart, $rentEnd, $partial, $input), 'confirmation' => $input ?? [],
                'sources' => ['contract_id' => $c->id, 'agreed_monthly_rent' => $c->monthly_rent, 'service_terms_version' => $c->service_terms_version, 'terms' => $c->agreedServices()->get()->toArray()]];
        }
        if ($hasMeters) {
            if ($updating) {
                app(UtilityPeriodBilling::class)->rebuildMonthlyDrafts($room, $period, $usageStartAt, $usageEndAt, $actor);
            } else {
                app(UtilityPeriodBilling::class)->generate($room, $period, $usageStartAt, $usageEndAt, $actor);
            }
        } else {
            $this->check($updating || $existing->isEmpty(), 'invoice_review', 'Kỳ đã có hóa đơn cũ; cần đối soát tránh thu trùng.');
            if ($updating) {
                foreach ($existing as $invoice) {
                    $invoice->items()->where('source', 'AUTO')->delete();
                }
            }
        }
        $invoices = [];
        foreach ($plans as $contractId => $plan) {
            $obligation = DB::table('invoice_obligations')->where('room_id', $room->id)->where('contract_id', $contractId)->where('billing_period', $period)->first();
            $invoice = $obligation ? Invoice::findOrFail($obligation->invoice_id) : $room->invoices()->create(['contract_id' => $contractId, 'billing_period' => $period, 'status' => 'DRAFT', 'discount_amount' => 0, 'note' => 'Hóa đơn tháng V2 — kiểm tra trước khi phát hành.']);
            if (! $obligation) {
                DB::table('invoice_obligations')->insert(['room_id' => $room->id, 'contract_id' => $contractId, 'billing_period' => $period, 'kind' => 'UTILITY_V2', 'invoice_id' => $invoice->id]);
            }
            $this->check($updating || ! $invoice->items()->where('source', 'MANUAL')->exists(), 'invoice_review', 'Có khoản thủ công: cần đối soát trước khi bổ sung tự động.');
            foreach ($plan['lines'] as $line) {
                $invoice->items()->create($line);
            }
            $invoice->recalculateTotals();
            $composition = ['snapshot' => json_encode(['lines' => $plan['lines'], 'sources' => $plan['sources'], 'usage_window' => ['start' => $usageStart->format('Y-m-d H:i:s'), 'end' => $usageEnd->format('Y-m-d H:i:s'), 'timezone' => 'Asia/Ho_Chi_Minh'], 'usage_periods' => $this->usagePeriods($room, $period)], JSON_THROW_ON_ERROR), 'confirmation' => json_encode($plan['confirmation'], JSON_THROW_ON_ERROR), 'updated_at' => now()];
            if ($updating) {
                DB::table('monthly_invoice_compositions')->where('invoice_id', $invoice->id)->update($composition);
            } else {
                DB::table('monthly_invoice_compositions')->insert([...$composition, 'invoice_id' => $invoice->id, 'contract_id' => $contractId, 'billing_period' => $period, 'created_by' => $actor, 'created_at' => now()]);
            }
            $invoices[] = $invoice->fresh()->load('items')->setAttribute('monthly_confirmation', $plan['confirmation']);
        }

        $allInvoices = Invoice::query()->whereIn('id', DB::table('invoice_obligations')->where('room_id', $room->id)->where('billing_period', $period)->pluck('invoice_id'))->with('items')->orderBy('id')->get();
        foreach ($allInvoices as $invoice) {
            $composition = DB::table('monthly_invoice_compositions')->where('invoice_id', $invoice->id)->first();
            if ($composition) {
                $invoice->setAttribute('monthly_confirmation', json_decode($composition->confirmation, true));
            }
        }

        return [...$base, 'status' => $allInvoices->isEmpty() ? 'NO_NEW_RECEIVABLES' : 'CREATED', 'invoices' => $allInvoices->all()];
    }

    /** Rent is monthly from the contract anniversary, not necessarily the first of the calendar month.
     * The invoice month remains the accounting label; actual usage dates govern private-room charges.
     * KTX keeps its existing calendar-month policy.
     */
    private function partialPeriod(Contract $contract, Collection $contracts, CarbonImmutable $start, CarbonImmutable $end, ?object $termination, bool $dormitory, CarbonImmutable $usageStart, CarbonImmutable $usageEnd, bool $calendarRent): bool
    {
        $sequential = $dormitory
            ? $contracts->where('bed_id', $contract->bed_id)->count() > 1
            : $contracts->count() > 1;

        // A bed starting on the 4th has a full rental cycle from the 4th to
        // the 4th. A calendar-month label must not turn that into a partial
        // month. This override applies to this bed only; another tenant on
        // the same bed, a shortened stay, or early checkout still needs review.
        if ($dormitory && $usageStart->gte($start) && $usageStart->lt($end)
            && ! $sequential
            && ContractMonthlyCycle::isFull(
                $contract->start_date->format('Y-m-d'),
                $usageStart->format('Y-m-d'),
                $usageEnd->format('Y-m-d')
            )
            && (! $contract->end_date || $contract->end_date->format('Y-m-d') >= $usageEnd->subDay()->format('Y-m-d'))
            && (! $termination || substr((string) $termination->actual_ended_at, 0, 10) >= $usageEnd->format('Y-m-d'))) {
            return false;
        }

        if ($calendarRent) {
            // Backdated meter windows must not turn the previous tenant into a
            // December rent obligation. This is also the established KTX rule.
            return $sequential || $contract->start_date->gt($start)
                || ($contract->end_date && $contract->end_date->lt($end->subDay()))
                || ($termination && $termination->actual_ended_at < $end->format('Y-m-d H:i:s'));
        }

        $fullContractMonth = ContractMonthlyCycle::isFull(
            $contract->start_date->format('Y-m-d'),
            $usageStart->format('Y-m-d'),
            $usageEnd->format('Y-m-d')
        );

        return $sequential || ! $fullContractMonth
            || ($contract->end_date && $contract->end_date->format('Y-m-d') < $usageEnd->subDay()->format('Y-m-d'))
            || ($termination && substr((string) $termination->actual_ended_at, 0, 10) < $usageEnd->format('Y-m-d'));
    }

    private function isAbandoned(Contract $contract, string $period, CarbonImmutable $usageStart, CarbonImmutable $usageEnd, bool $dormitory): bool
    {
        $policy = app(ContractSettlementPolicy::class);
        // Bed rent follows the invoice month, which need not equal the utility window.
        return $dormitory ? $policy->abandoned($contract->id, $period) : $policy->abandonedWithin($contract->id, $usageStart, $usageEnd);
    }

    /** KTX: only each contract's bed rent is billed unless an agreed extra service is explicitly selected. */
    private function dormitoryLines(Contract $contract, Room $room, CarbonImmutable $start, CarbonImmutable $end, bool $partial, ?array $input): array
    {
        $this->check($contract->contract_type === 'DORM_BED' && $contract->bed_id !== null
            && $room->beds()->whereKey($contract->bed_id)->exists(), 'bed_id', 'KTX phải có hợp đồng thuê giường hợp lệ thuộc phòng này.');
        $this->check($contract->monthly_rent !== null, 'agreement', 'Thiếu tiền giường đã thỏa thuận trong hợp đồng.');
        $this->check(! $partial || isset($input['rent_amount']), 'policy_approval', "Hợp đồng giường #{$contract->id}: kỳ lẻ/chuyển giường cần chủ trọ xác nhận tiền giường.");
        $lines = [$this->line('RENT', 'Tiền giường '.$contract->bed->bed_code.' · '.$start->format('m/Y'), 1,
            $partial ? $input['rent_amount'] : $contract->monthly_rent, 'giường')];

        $selected = collect($input['services'] ?? []);
        if ($selected->isEmpty()) {
            // Included electricity/water/Internet/other service terms are not additional charges.
            return $lines;
        }
        $this->check(($input['extra_service_confirmed'] ?? false) === true && mb_strlen(trim((string) ($input['reason'] ?? ''))) >= 5,
            'extra_service_agreement', 'Thu thêm dịch vụ KTX phải có xác nhận thỏa thuận thu riêng và lý do.');
        $this->check($contract->service_terms_version !== null, 'agreement', 'Chưa có bảng giá dịch vụ được thỏa thuận trong hợp đồng.');
        $this->check($selected->pluck('term_id')->unique()->count() === $selected->count(), 'services', 'Không chọn trùng dịch vụ.');

        $terms = $contract->agreedServices()->where('is_active', true)
            ->whereDate('effective_from', '<', $end)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start))->get();
        foreach ($selected as $approval) {
            $term = $terms->firstWhere('id', $approval['term_id']);
            $this->check($term !== null, 'services', 'Dịch vụ chọn thu thêm chưa có hiệu lực trong hợp đồng.');
            $this->check($terms->where('service_id', $term->service_id)->count() === 1, 'tariff_conflict', 'Dịch vụ có nhiều phiên bản giá trong kỳ; cần đối soát.');
            $this->check($room->boardingHouse->services()->withTrashed()->whereKey($term->service_id)->exists(), 'service_id', 'Dịch vụ không thuộc nhà trọ.');
            app(ContractServiceTerms::class)->validatePricing($term->toArray());
            $this->check(! in_array($term->billing_method, ['TIERED'], true)
                && ! (in_array($term->service_type, ['ELECTRICITY', 'WATER'], true) && $term->billing_method === 'PER_UNIT'),
                'allocation_policy', 'Thu riêng điện/nước theo công tơ chung KTX chưa có phân bổ được xác nhận; không thu tự động.');
            $this->check(BigDecimal::of((string) $approval['quantity'])->isGreaterThan(0)
                && BigDecimal::of((string) $approval['amount'])->isGreaterThan(0), 'services', 'Dịch vụ thu riêng phải có số lượng và số tiền dương được xác nhận.');
            $lines[] = $this->line($term->service_type, $term->service_name.' · thu riêng theo xác nhận',
                (string) $approval['quantity'], $term->base_price, $term->unit, (string) $approval['amount']);
        }

        return $lines;
    }

    private function lines(Contract $c, Room $room, CarbonImmutable $start, CarbonImmutable $end, bool $partial, ?array $input): array
    {
        if ($room->room_type === 'DORMITORY') {
            return $this->dormitoryLines($c, $room, $start, $end, $partial, $input);
        }
        $this->check($c->monthly_rent !== null && $c->service_terms_version !== null, 'agreement', 'Thiếu giá thuê hoặc bảng giá thỏa thuận.');
        $this->check(! $partial || isset($input['rent_amount']), 'policy_approval', "Hợp đồng #{$c->id}: kỳ lẻ/nối tiếp cần xác nhận tiền phòng, FIXED và số người.");
        $lines = [$this->line('RENT', 'Tiền phòng '.$start->format('m/Y'), 1, $partial ? $input['rent_amount'] : $c->monthly_rent, 'tháng')];
        $this->check(! $c->agreedServices()->where('is_active', true)->whereNull('effective_from')->exists(), 'agreement', 'Dịch vụ thiếu ngày hiệu lực thỏa thuận; không tự bỏ qua khoản thu.');
        $terms = $c->agreedServices()->whereDate('effective_from', '<', $end)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start))->get();
        $this->check(! $terms->groupBy('service_id')->contains(fn ($group) => $group->count() > 1), 'tariff_conflict', 'Nhiều phiên bản giá trong kỳ; cần đối soát.');
        foreach ($terms->where('is_active', true) as $term) {
            $this->check($term->service_id !== null && $room->boardingHouse->services()->withTrashed()->whereKey($term->service_id)->exists(), 'service_id', 'Dịch vụ không thuộc nhà trọ.');
            app(ContractServiceTerms::class)->validatePricing($term->toArray());
            if (in_array($term->service_type, ['ELECTRICITY', 'WATER'], true) && in_array($term->billing_method, ['PER_UNIT', 'TIERED'], true)) {
                $this->check($room->utilityMeters()->where('service_id', $term->service_id)->where('is_active', true)->exists(), 'checkpoints', 'Thiếu công tơ cho dịch vụ thỏa thuận.');

                continue;
            }
            $approval = collect($input['services'] ?? [])->firstWhere('term_id', $term->id);
            $this->check($term->billing_method !== 'TIERED', 'allocation_policy', 'Dịch vụ bậc thang cần công tơ/kỳ và chính sách phân bổ; không tự reset bậc cho từng hợp đồng.');
            if ($partial || ! in_array($term->billing_method, ['FIXED', 'PER_PERSON'], true) || $term->effective_from->gt($start) || ($term->effective_to && $term->effective_to->lt($end->subDay()))) {
                $this->check($approval !== null, 'policy_approval', "Dịch vụ #{$term->id} ({$term->service_name}): cần xác nhận lượng/số người và tiền theo thỏa thuận.");
                $lines[] = $this->line($term->service_type, $term->service_name.' · đã xác nhận', (string) $approval['quantity'], $term->base_price ?? 0, $term->unit, $partial ? (string) $approval['amount'] : MonthlyChargeAmount::calculate((string) $approval['quantity'], $term->base_price));

                continue;
            }
            $count = 1;
            if ($term->billing_method === 'PER_PERSON') {
                $this->check(! $room->roomTenants()->whereNull('contract_id')->whereDate('move_in_date', '<', $end)->where(fn ($q) => $q->whereNull('move_out_date')->orWhereDate('move_out_date', '>=', $start))->exists(), 'occupants', 'Lịch sử cư trú chưa liên kết hợp đồng.');
                $count = $c->occupants()->whereIn('status', ['ACTIVE', 'MOVED_OUT'])->whereDate('move_in_date', '<', $end)->where(fn ($q) => $q->whereNull('move_out_date')->orWhereDate('move_out_date', '>=', $start))->distinct()->count('tenant_id');
                $this->check($count > 0, 'occupants', 'Thiếu lịch sử số người PER_PERSON.');
            }
            $lines[] = $this->line($term->service_type, $term->service_name, $count, $term->base_price, $term->unit);
        }

        return $lines;
    }

    /** Called under the issue command's room lock; never rewrites a posted document. */
    public function assertSourcesCurrent(Invoice $invoice): void
    {
        $record = DB::table('monthly_invoice_compositions')->where('invoice_id', $invoice->id)->first();
        if (! $record) {
            return;
        }
        $room = $invoice->room;
        $start = CarbonImmutable::parse($record->billing_period);
        $end = $start->addMonth();
        $snapshot = json_decode($record->snapshot, true);
        $window = $snapshot['usage_window'] ?? ['start' => $record->billing_period.' 00:00:00', 'end' => CarbonImmutable::parse($record->billing_period)->addMonth()->format('Y-m-d H:i:s')];
        $usageStart = CarbonImmutable::parse($window['start'], 'Asia/Ho_Chi_Minh');
        $usageEnd = CarbonImmutable::parse($window['end'], 'Asia/Ho_Chi_Minh');
        $calendarRent = $room->room_type === 'DORMITORY' || $usageStart->lt($start);
        $rentStart = $calendarRent ? $start : $usageStart;
        $rentEnd = $calendarRent ? $end : $usageEnd;
        $contracts = $this->contractsForPeriod($room, $rentStart, $rentEnd);
        $normalizedWindow = ['start' => $usageStart->format('Y-m-d H:i:s'), 'end' => $usageEnd->format('Y-m-d H:i:s'), 'timezone' => 'Asia/Ho_Chi_Minh'];
        $currentIds = $contracts->reject(fn ($c) => $this->isAbandoned($c, $record->billing_period, $usageStart, $usageEnd, $room->room_type === 'DORMITORY'))->pluck('id')->sort()->values()->all();
        $savedIds = DB::table('monthly_invoice_compositions')->whereIn('invoice_id', $room->invoices()->select('id'))->where('billing_period', $record->billing_period)->pluck('contract_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->check($currentIds === $savedIds, 'stale_monthly_sources', 'Hợp đồng trong kỳ đã thay đổi; cần xem trước và đối soát draft.');
        $c = $contracts->firstWhere('id', $invoice->contract_id);
        $this->check($c !== null && $c->handoverReports()->where('type', 'CHECK_IN')->where('status', 'CONFIRMED')->exists(), 'stale_monthly_sources', 'Thiếu bằng chứng bàn giao; chưa được phát hành.');
        $termination = DB::table('contract_terminations')->where('contract_id', $c->id)->first();
        $partial = $this->partialPeriod($c, $contracts, $start, $end, $termination, $room->room_type === 'DORMITORY', $usageStart, $usageEnd, $calendarRent);
        $sources = ['contract_id' => $c->id, 'agreed_monthly_rent' => $c->monthly_rent, 'service_terms_version' => $c->service_terms_version, 'terms' => $c->agreedServices()->get()->toArray()];
        $lines = $this->lines($c, $room, $rentStart, $rentEnd, $partial, json_decode($record->confirmation, true));
        $this->check($sources == $snapshot['sources'] && $lines == $snapshot['lines'] && $normalizedWindow == ($snapshot['usage_window'] ?? $normalizedWindow) && $this->usagePeriods($room, $record->billing_period) == ($snapshot['usage_periods'] ?? []), 'stale_monthly_sources', 'Nguồn tiền phòng/dịch vụ hoặc kỳ công tơ đã đổi. Hãy xem trước và cập nhật draft trước khi phát hành.');
    }

    private function usagePeriods(Room $room, string $period): array
    {
        return DB::table('utility_billing_periods')->whereIn('utility_meter_id', $room->utilityMeters()->withTrashed()->select('id'))
            ->where('billing_period', $period)->orderBy('utility_meter_id')
            ->get(['utility_meter_id', 'billing_period', 'timezone', 'usage_start_at', 'usage_end_at', 'start_checkpoint_id', 'end_checkpoint_id', 'start_confirmed_by', 'end_confirmed_by', 'start_confirmed_at', 'end_confirmed_at', 'calculated_by', 'calculated_at', 'quantity', 'tariff_total', 'snapshot'])
            ->map(fn ($cycle) => (array) $cycle)->all();
    }

    private function contractsForPeriod(Room $room, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return $room->contracts()->whereIn('status', ['ACTIVE', 'EXPIRED', 'TERMINATED'])->whereDate('start_date', '<', $end)->get()->filter(function ($c) use ($start) {
            $termination = DB::table('contract_terminations')->where('contract_id', $c->id)->first();

            return $termination ? $termination->actual_ended_at > $start->format('Y-m-d H:i:s') : (! $c->end_date || $c->end_date->gte($start));
        });
    }

    private function line(string $type, string $description, int|string|float $quantity, int|string|float $price, ?string $unit, int|string|float|null $amount = null): array
    {
        return ['type' => $type, 'description' => $description, 'quantity' => (string) $quantity, 'unit_price' => (string) $price, 'unit' => $unit, 'amount' => MonthlyChargeAmount::calculate((string) $quantity, (string) $price, $amount === null ? null : (string) $amount), 'source' => 'AUTO'];
    }

    public function isUtilityOnlyInvoice(Invoice $invoice): bool
    {
        return str_starts_with((string) $invoice->note, 'Quyết toán điện nước V2')
            && $invoice->items()->exists()
            && ! $invoice->items()->where('source', '!=', 'AUTO')->exists()
            && ! $invoice->items()->whereNotIn('type', ['ELECTRICITY', 'WATER'])->exists();
    }

    private function check(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
