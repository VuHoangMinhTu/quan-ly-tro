<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Room;
use App\Models\UtilityMeter;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BillingV2Evidence
{
    /** Append confirmed business-local (Asia/Ho_Chi_Minh) evidence, never rewrite old checkpoints. */
    public function checkpoint(UtilityMeter $meter, array $data, int $actor): int
    {
        return DB::transaction(function () use ($meter, $data, $actor): int {
            Room::query()->lockForUpdate()->findOrFail($meter->room_id);
            $this->require((int) $meter->service?->boarding_house_id === (int) $meter->room->boarding_house_id, 'utility_meter_id', 'Công tơ và dịch vụ phải thuộc cùng nhà trọ.');
            $this->require(BigDecimal::of($data['reading_value'])->isGreaterThanOrEqualTo((string) $meter->initial_reading), 'reading_value', 'Chỉ số V2 không được thấp hơn số gốc của đồng hồ. Nếu thay hoặc đặt lại công tơ, hãy đăng ký đúng đồng hồ mới.');
            $contract = isset($data['contract_id']) ? $meter->room->contracts()->findOrFail($data['contract_id']) : null;
            if ($data['kind'] === 'PERIOD_BOUNDARY') {
                $this->require($contract === null, 'contract_id', 'Mốc đầu/cuối kỳ sử dụng không gắn riêng một hợp đồng.');
                $scope = 'P:'.$data['observed_at'];
            } else {
                $this->require($contract !== null && ! $contract->is_demo && $contract->status !== 'DRAFT', 'contract_id', 'Mốc bàn giao phải thuộc hợp đồng thực tế đã kích hoạt trong phòng.');
                $this->require((int) $contract->tenant?->landlord_id === (int) $meter->room->boardingHouse->landlord_id, 'contract_id', 'Hợp đồng/người thuê không thuộc đúng chủ trọ.');
                $this->require(substr($data['observed_at'], 0, 10) >= $contract->start_date->toDateString(), 'observed_at', 'Ngày bàn giao không được trước ngày bắt đầu hợp đồng.');
                $scope = $data['kind'].':'.$contract->id;
                if ($data['kind'] === 'CHECK_OUT') {
                    $end = DB::table('contract_terminations')->where('contract_id', $contract->id)->first();
                    $this->require($end !== null && $end->actual_ended_at === $data['observed_at'], 'observed_at', 'CHECK_OUT phải khớp thời điểm kết thúc thực tế.');
                }
            }
            $current = DB::table('utility_checkpoints')->where('utility_meter_id', $meter->id)->orderByDesc('revision')->get()->unique('scope_key');
            $previous = $current->firstWhere('scope_key', $scope);
            if ($previous && $previous->observed_at === $data['observed_at'] && BigDecimal::of($previous->reading_value)->isEqualTo($data['reading_value'])) {
                return $previous->id;
            }
            // A corrected boundary keeps its historical scope key, which can differ from its new timestamp.
            // Reuse it rather than creating a second active period boundary at that same timestamp.
            if ($data['kind'] === 'PERIOD_BOUNDARY' && ! $previous) {
                $sameBoundary = $current->first(fn ($point) => $point->kind === 'PERIOD_BOUNDARY' && $point->observed_at === $data['observed_at']);
                if ($sameBoundary && BigDecimal::of($sameBoundary->reading_value)->isEqualTo($data['reading_value'])) {
                    return $sameBoundary->id;
                }
            }
            // Protect against contradictory V1 measurements on the same physical meter.
            // Legacy readings are historical, not automatically copied/updated by V2.
            $date = substr($data['observed_at'], 0, 10);
            $beforeLegacy = $meter->readings()->whereDate('reading_date', '<=', $date)
                ->reorder('reading_date', 'desc')->orderByDesc('id')->first();
            $afterLegacy = $meter->readings()->whereDate('reading_date', '>=', $date)
                ->reorder('reading_date')->orderBy('id')->first();
            if ($beforeLegacy && BigDecimal::of($data['reading_value'])->isLessThan($beforeLegacy->reading_value)) {
                throw ValidationException::withMessages(['reading_value' => 'Mốc V2 nhỏ hơn lịch sử chỉ số V1 cùng công tơ ('.$beforeLegacy->reading_date->format('d/m/Y').'). Hãy đối soát số gốc và lịch sử trước khi xác nhận.']);
            }
            if ($afterLegacy && BigDecimal::of($data['reading_value'])->isGreaterThan($afterLegacy->reading_value)) {
                throw ValidationException::withMessages(['reading_value' => 'Mốc V2 lớn hơn lịch sử chỉ số V1 kế tiếp ('.$afterLegacy->reading_date->format('d/m/Y').'). Hãy đối soát, không sửa số để vượt kiểm tra.']);
            }
            foreach ($current->where('scope_key', '!=', $scope) as $point) {
                $comparison = BigDecimal::of($data['reading_value'])->compareTo($point->reading_value);
                $this->require(($point->observed_at < $data['observed_at'] && $comparison >= 0) || ($point->observed_at > $data['observed_at'] && $comparison <= 0) || ($point->observed_at === $data['observed_at'] && $comparison === 0), 'reading_value', 'Chỉ số mâu thuẫn với mốc đã xác nhận trước/sau hoặc cùng thời điểm.');
            }

            return DB::table('utility_checkpoints')->insertGetId(['utility_meter_id' => $meter->id, 'contract_id' => $contract?->id, 'kind' => $data['kind'], 'scope_key' => $scope,
                'revision' => ($previous?->revision ?? 0) + 1, 'observed_at' => $data['observed_at'], 'reading_value' => $data['reading_value'], 'confirmed_by' => $actor, 'created_at' => now()]);
        }, 3);
    }

    /**
     * Correct a saved pair without rewriting the original evidence. Old revisions stay intact.
     * A corrected reading retains its historical scope key, while its observed_at may change.
     * Only the latest revision for each scope is effective in utility calculations.
     */
    public function correctPeriodBoundaries(UtilityMeter $meter, array $data, int $actor): array
    {
        return DB::transaction(function () use ($meter, $data, $actor): array {
            Room::query()->lockForUpdate()->findOrFail($meter->room_id);
            UtilityMeter::query()->lockForUpdate()->findOrFail($meter->id);
            $this->require((int) $meter->service?->boarding_house_id === (int) $meter->room->boarding_house_id, 'utility_meter_id', 'Công tơ không thuộc đúng nhà trọ.');

            $payload = [
                'start_checkpoint_id' => (int) $data['start_checkpoint_id'],
                'end_checkpoint_id' => (int) $data['end_checkpoint_id'],
                'start_observed_at' => $data['start_observed_at'],
                'end_observed_at' => $data['end_observed_at'],
                'start_reading_value' => (string) BigDecimal::of($data['start_reading_value'])->strippedOfTrailingZeros(),
                'end_reading_value' => (string) BigDecimal::of($data['end_reading_value'])->strippedOfTrailingZeros(),
                'reason' => trim($data['reason']),
            ];
            $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            $oldRequest = DB::table('utility_checkpoint_corrections')->where('utility_meter_id', $meter->id)->where('request_key', $data['request_key'])->first();
            if ($oldRequest) {
                $this->require(hash_equals($oldRequest->request_hash, $fingerprint), 'request_key', 'Mã thao tác đã được dùng cho nội dung chỉnh sửa khác.');

                return ['start_checkpoint_id' => $oldRequest->new_start_checkpoint_id, 'end_checkpoint_id' => $oldRequest->new_end_checkpoint_id, 'replayed' => true];
            }

            $this->require($payload['start_checkpoint_id'] !== $payload['end_checkpoint_id'], 'checkpoints', 'Hai mốc phải là hai bản ghi khác nhau.');
            $this->require($data['start_observed_at'] < $data['end_observed_at'], 'end_observed_at', 'Ngày giờ kết thúc phải sau ngày giờ bắt đầu.');
            // Corrections must reconcile with any V1 readings of this meter, too.
            foreach ([[$data['start_observed_at'], $data['start_reading_value']], [$data['end_observed_at'], $data['end_reading_value']]] as [$observed, $value]) {
                $date = substr($observed, 0, 10);
                $prior = $meter->readings()->whereDate('reading_date', '<=', $date)->reorder('reading_date', 'desc')->orderByDesc('id')->first();
                $next = $meter->readings()->whereDate('reading_date', '>=', $date)->reorder('reading_date')->orderBy('id')->first();
                $this->require(! $prior || ! BigDecimal::of($value)->isLessThan($prior->reading_value), 'reading_value', 'Mốc sửa nhỏ hơn số V1 đã ghi trước đó; hãy đối soát.');
                $this->require(! $next || ! BigDecimal::of($value)->isGreaterThan($next->reading_value), 'reading_value', 'Mốc sửa lớn hơn số V1 ghi kế tiếp; hãy đối soát.');
            }

            $this->require(BigDecimal::of($data['end_reading_value'])->isGreaterThanOrEqualTo($data['start_reading_value']), 'end_reading_value', 'Chỉ số cuối không được nhỏ hơn chỉ số đầu.');

            $originals = DB::table('utility_checkpoints')->where('utility_meter_id', $meter->id)
                ->whereIn('id', [$payload['start_checkpoint_id'], $payload['end_checkpoint_id']])->get()->keyBy('id');
            $start = $originals->get($payload['start_checkpoint_id']);
            $end = $originals->get($payload['end_checkpoint_id']);
            $this->require($start && $end && $start->kind === 'PERIOD_BOUNDARY' && $end->kind === 'PERIOD_BOUNDARY' && $start->scope_key !== $end->scope_key, 'checkpoints', 'Mốc cần sửa không hợp lệ hoặc không thuộc công tơ.');
            foreach ([$start, $end] as $old) {
                $latestId = DB::table('utility_checkpoints')->where('utility_meter_id', $meter->id)
                    ->where('scope_key', $old->scope_key)->orderByDesc('revision')->value('id');
                $this->require((int) $latestId === (int) $old->id, 'checkpoints', 'Mốc đã có phiên bản mới. Hãy tải lại để tránh ghi đè chỉnh sửa của người khác.');
            }
            $this->require($start->observed_at < $end->observed_at, 'checkpoints', 'Hai mốc nguồn không đúng thứ tự thời gian.');

            // Never mutate a meter period that overlaps an issued, paid or payment-linked invoice.
            // Includes adjacent bill boundaries, since they may be shared across billing periods.
            $times = [$start->observed_at, $end->observed_at, $data['start_observed_at'], $data['end_observed_at']];
            sort($times);
            $affected = DB::table('utility_billing_periods')->where('utility_meter_id', $meter->id)
                ->where('usage_start_at', '<=', max($times))->where('usage_end_at', '>=', min($times))->pluck('billing_period');
            $invoiceIds = DB::table('invoices')->where('room_id', $meter->room_id)
                ->whereIn('billing_period', $affected)->pluck('id');
            $protected = DB::table('invoices')->whereIn('id', $invoiceIds)
                ->where(function ($query) {
                    $query->where('status', '!=', 'DRAFT')->orWhereNotNull('issued_at')
                        ->orWhere('paid_amount', '>', 0);
                })->exists();
            $linkedPayments = DB::table('payments')->whereIn('invoice_id', $invoiceIds)->exists();
            // Also guard PayOS-linked documents; do not risk changing a provider-linked draft.
            $linkedPayos = DB::table('payos_payment_requests')->whereIn('invoice_id', $invoiceIds)->exists();
            $this->require(! $protected && ! $linkedPayments && ! $linkedPayos, 'checkpoints', 'Mốc có liên quan đến hóa đơn đã phát hành/thanh toán hoặc yêu cầu PayOS. Chỉ được điều chỉnh bằng chứng từ riêng.');

            $all = DB::table('utility_checkpoints')->where('utility_meter_id', $meter->id)
                ->orderByDesc('revision')->get()->unique('scope_key');
            $other = $all->reject(fn ($point) => in_array($point->scope_key, [$start->scope_key, $end->scope_key], true));
            foreach ([[$data['start_observed_at'], $data['start_reading_value']], [$data['end_observed_at'], $data['end_reading_value']]] as [$time, $value]) {
                foreach ($other as $point) {
                    $cmp = BigDecimal::of($value)->compareTo($point->reading_value);
                    $this->require(($point->observed_at < $time && $cmp >= 0) || ($point->observed_at > $time && $cmp <= 0) || ($point->observed_at === $time && $cmp === 0), 'reading_value', 'Chỉ số/ngày giờ mới mâu thuẫn với mốc khác đã xác nhận. Kiểm tra lịch sử công tơ trước khi lưu.');
                }
            }

            $newIds = [];
            foreach ([[$start, $data['start_observed_at'], $data['start_reading_value']], [$end, $data['end_observed_at'], $data['end_reading_value']]] as [$old, $time, $value]) {
                if ($old->observed_at === $time && BigDecimal::of($old->reading_value)->isEqualTo($value)) {
                    $newIds[] = $old->id;
                    continue;
                }
                $newIds[] = DB::table('utility_checkpoints')->insertGetId([
                    'utility_meter_id' => $meter->id, 'contract_id' => null, 'kind' => 'PERIOD_BOUNDARY',
                    'scope_key' => $old->scope_key, 'revision' => $old->revision + 1,
                    'observed_at' => $time, 'reading_value' => $value,
                    'confirmed_by' => $actor, 'created_at' => now(),
                ]);
            }
            $this->require($newIds[0] !== $newIds[1], 'checkpoints', 'Hai mốc sau chỉnh sửa phải khác nhau.');
            DB::table('utility_checkpoint_corrections')->insert([
                'utility_meter_id' => $meter->id, 'old_start_checkpoint_id' => $start->id, 'old_end_checkpoint_id' => $end->id,
                'new_start_checkpoint_id' => $newIds[0], 'new_end_checkpoint_id' => $newIds[1],
                'request_key' => $data['request_key'], 'request_hash' => $fingerprint,
                'reason' => $payload['reason'], 'corrected_by' => $actor, 'created_at' => now(),
            ]);

            return ['start_checkpoint_id' => $newIds[0], 'end_checkpoint_id' => $newIds[1], 'replayed' => false,
                'draft_requires_recalculation' => $affected->isNotEmpty()];
        }, 3);
    }

    public function deposit(Contract $contract, array $data, int $actor): int
    {
        return DB::transaction(function () use ($contract, $data, $actor): int {
            Room::query()->lockForUpdate()->findOrFail($contract->room_id);
            Contract::query()->lockForUpdate()->findOrFail($contract->id);
            $existing = DB::table('contract_deposit_entries')->where('contract_id', $contract->id)->where('request_key', $data['request_key'])->first();
            if ($existing) {
                $this->require($existing->event === $data['event'] && BigDecimal::of($existing->amount)->isEqualTo($data['amount']) && $existing->occurred_at === $data['occurred_at'], 'request_key', 'Khóa ghi cọc đã được dùng với dữ liệu khác.');

                return $existing->id;
            }
            $this->require($data['event'] !== 'FORFEITED' || DB::table('contract_terminations')->where('contract_id', $contract->id)->where('reason', 'TENANT_ABANDONMENT')->exists(), 'event', 'Chỉ mất cọc theo chính sách bỏ ngang đã xác nhận.');
            $this->require($data['event'] === 'RECEIVED' || BigDecimal::of($data['amount'])->isLessThanOrEqualTo($this->heldDeposit($contract)), 'amount', 'Không thể hoàn/mất cọc vượt số tiền thực tế đang giữ.');
            $this->require($data['event'] !== 'RECEIVED' || ! DB::table('contract_terminations')->where('contract_id', $contract->id)->exists(), 'event', 'Hợp đồng đã quyết toán kết thúc; khoản cọc phát sinh cần đối soát riêng.');
            $latest = DB::table('contract_deposit_entries')->where('contract_id', $contract->id)->max('occurred_at');
            $this->require($latest === null || $data['occurred_at'] >= $latest, 'occurred_at', 'Thời điểm giao dịch không được trước giao dịch cọc đã ghi nhận.');
            $this->require($data['event'] !== 'RECEIVED' || BigDecimal::of($this->heldDeposit($contract))->plus($data['amount'])->isLessThanOrEqualTo('999999999999999'), 'amount', 'Số dư cọc vượt giới hạn sổ cọc.');

            return DB::table('contract_deposit_entries')->insertGetId(['contract_id' => $contract->id, 'event' => $data['event'], 'amount' => $data['amount'], 'occurred_at' => $data['occurred_at'], 'request_key' => $data['request_key'], 'recorded_by' => $actor, 'created_at' => now()]);
        }, 3);
    }

    public function heldDeposit(Contract $contract): string
    {
        $balance = BigDecimal::zero();
        foreach (DB::table('contract_deposit_entries')->where('contract_id', $contract->id)->get() as $entry) {
            $balance = $entry->event === 'RECEIVED' ? $balance->plus($entry->amount) : $balance->minus($entry->amount);
        }

        return (string) $balance;
    }

    public function checkout(Contract $contract, array $data, int $actor): void
    {
        DB::transaction(function () use ($contract, $data, $actor): void {
            Room::query()->lockForUpdate()->findOrFail($contract->room_id);
            $contract = Contract::query()->lockForUpdate()->findOrFail($contract->id);
            $this->require($contract->room->room_type === 'PRIVATE_ROOM', 'room_id', 'CHECK_OUT V2 hiện dành cho hợp đồng phòng riêng.');
            $existing = DB::table('contract_terminations')->where('contract_id', $contract->id)->first();
            if ($existing) {
                $this->require($existing->actual_ended_at === $data['actual_ended_at'] && $existing->reason === $data['reason'], 'reason', 'Kết thúc đã xác nhận; không tự sửa lý do/thời điểm lịch sử.');
                $report = $contract->handoverReports()->where('type', 'CHECK_OUT')->firstOrFail();
                $normalize = fn (array $readings) => collect($readings)->sortBy('utility_meter_id')->map(fn ($row) => [(int) $row['utility_meter_id'], (string) BigDecimal::of($row['reading_value'])->strippedOfTrailingZeros()])->values()->all();
                $this->require($normalize($report->items) === $normalize($data['readings']), 'readings', 'Bàn giao đã xác nhận với chỉ số khác; cần quy trình đính chính riêng.');

                return;
            }
            $this->require($contract->status === 'ACTIVE' && ! $contract->is_demo && substr($data['actual_ended_at'], 0, 10) >= $contract->start_date->toDateString(), 'actual_ended_at', 'Chỉ quyết toán hợp đồng thực tế đang hoạt động, sau thời điểm nhận phòng.');
            if ($data['reason'] === 'NORMAL_EXPIRY') {
                $this->require($contract->end_date !== null && substr($data['actual_ended_at'], 0, 10) >= $contract->end_date->toDateString(), 'reason', 'Chưa đến ngày hết hạn hợp đồng.');
            }
            $meters = $contract->room->utilityMeters()->where('is_active', true)->orderBy('id')->get();
            $ids = array_column($data['readings'], 'utility_meter_id');
            sort($ids);
            $this->require($ids === $meters->pluck('id')->all(), 'readings', 'Phải chốt đủ các công tơ của phòng, không trùng và không dùng công tơ ngoài phòng.');
            DB::table('contract_terminations')->insert(['contract_id' => $contract->id, 'actual_ended_at' => $data['actual_ended_at'], 'reason' => $data['reason'], 'recorded_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($data['readings'] as $row) {
                $meter = $meters->firstWhere('id', $row['utility_meter_id']);
                $in = DB::table('utility_checkpoints')->where('utility_meter_id', $meter->id)->where('scope_key', 'CHECK_IN:'.$contract->id)->orderByDesc('revision')->first();
                $this->require($in !== null && $in->observed_at <= $data['actual_ended_at'], 'readings', 'Thiếu CHECK_IN hợp lệ trước thời điểm trả phòng.');
                $this->checkpoint($meter, ['kind' => 'CHECK_OUT', 'contract_id' => $contract->id, 'observed_at' => $data['actual_ended_at'], 'reading_value' => $row['reading_value']], $actor);
            }
            $contract->handoverReports()->create(['type' => 'CHECK_OUT', 'status' => 'CONFIRMED', 'items' => $data['readings'], 'confirmed_at' => now()]);
            $heldBefore = $this->heldDeposit($contract);
            if ($data['reason'] === 'TENANT_ABANDONMENT' && BigDecimal::of($this->heldDeposit($contract))->isPositive()) {
                $this->deposit($contract, ['event' => 'FORFEITED', 'amount' => $this->heldDeposit($contract), 'request_key' => 'CHECK_OUT:'.$contract->id, 'occurred_at' => $data['actual_ended_at']], $actor);
            }
            app(ContractSettlementPolicy::class)->record($contract, $data, $actor, $heldBefore);
            $date = substr($data['actual_ended_at'], 0, 10);
            $this->require(! $contract->occupants()->where('status', 'ACTIVE')->whereDate('move_in_date', '>', $date)->exists(), 'actual_ended_at', 'Ngày kết thúc trước lịch sử vào ở.');
            $contract->occupants()->where('status', 'ACTIVE')->update(['status' => 'MOVED_OUT', 'move_out_date' => $date]);
            $contract->update(['status' => $data['reason'] === 'NORMAL_EXPIRY' ? 'EXPIRED' : 'TERMINATED']);
            $contract->room->update(['status' => $contract->room->roomTenants()->where('status', 'ACTIVE')->exists() ? 'RENTED' : 'AVAILABLE']);
        }, 3);
    }

    private function require(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
