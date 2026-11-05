<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Room;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContractSettlementPolicy
{
    public function period(string $endedAt): string
    {
        return CarbonImmutable::parse($endedAt)->subSecond()->startOfMonth()->toDateString();
    }

    /** Called inside checkout's room/contract transaction after the original evidence is saved. */
    public function record(Contract $contract, array $data, int $actor, string $heldBefore): void
    {
        $termination = DB::table('contract_terminations')->where('contract_id', $contract->id)->firstOrFail();
        $abandoned = $data['reason'] === 'TENANT_ABANDONMENT';
        $confirmed = ! $abandoned || BigDecimal::of($heldBefore)->isPositive() || ($data['confirm_no_held_deposit'] ?? false);
        $period = $this->period($data['actual_ended_at']);
        $existing = Invoice::withTrashed()->where('contract_id', $contract->id)->whereDate('billing_period', $period)->where('status', '!=', 'CANCELLED')->pluck('id')->all();
        $id = DB::table('contract_settlements')->insertGetId([
            'contract_id' => $contract->id, 'contract_termination_id' => $termination->id, 'billing_period' => $period,
            'status' => 'PENDING_FINAL_SETTLEMENT', 'collection_policy' => $abandoned ? 'NO_NEW_AUTO_RECEIVABLES' : 'AGREEMENT_REQUIRED',
            'confirmation_state' => $confirmed ? 'CONFIRMED' : 'NEEDS_OWNER_CONFIRMATION',
            'deposit_status' => $abandoned ? (BigDecimal::of($heldBefore)->isPositive() ? 'FORFEITED_ACTUAL_BALANCE' : ($confirmed ? 'NO_HELD_DEPOSIT_CONFIRMED' : 'NEEDS_OWNER_CONFIRMATION')) : 'NOT_AUTOMATICALLY_SETTLED',
            'invoice_review_status' => $abandoned && $existing !== [] ? 'NEEDS_INVOICE_REVIEW' : 'NONE',
            'policy_snapshot' => json_encode(['version' => 1, 'reason' => $data['reason'], 'held_before_checkout' => $heldBefore, 'existing_invoice_ids' => $existing,
                'waived_auto_components' => $abandoned ? ['RENT', 'FIXED', 'PER_PERSON', 'PER_UNIT', 'TIERED', 'OTHER_AUTO_SERVICES'] : [],
                'service_ids' => $contract->agreedServices()->pluck('service_id')->all(), 'supplier_cost' => null, 'no_automatic_debt_or_deposit_offset' => true], JSON_THROW_ON_ERROR),
            'confirmed_by' => $confirmed ? $actor : null, 'confirmed_at' => $confirmed ? now() : null,
            'confirmation_reason' => $abandoned && ! BigDecimal::of($heldBefore)->isPositive() && $confirmed ? 'Owner explicitly confirmed no held deposit at checkout.' : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $points = DB::table('utility_checkpoints')->where('contract_id', $contract->id)->orderByDesc('revision')->get()->unique(fn ($point) => $point->utility_meter_id.':'.$point->scope_key);
        foreach ($points->where('kind', 'CHECK_OUT') as $out) {
            $in = $points->first(fn ($point) => $point->kind === 'CHECK_IN' && $point->utility_meter_id === $out->utility_meter_id);
            DB::table('contract_settlement_usages')->insert([
                'contract_settlement_id' => $id, 'utility_meter_id' => $out->utility_meter_id, 'check_in_checkpoint_id' => $in->id, 'check_out_checkpoint_id' => $out->id,
                'period_opening_checkpoint_id' => null, 'quantity_since_check_in' => (string) BigDecimal::of($out->reading_value)->minus($in->reading_value),
                'checkout_period_quantity' => null,
                'final_period_quantity' => null, 'tariff_amount' => null, 'responsible_party' => $abandoned ? 'LANDLORD' : 'TENANT', 'status' => 'PENDING_FINAL_SETTLEMENT',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function confirmNoHeldDeposit(Contract $contract, int $actor, string $reason): void
    {
        DB::transaction(function () use ($contract, $actor, $reason): void {
            Room::query()->lockForUpdate()->findOrFail($contract->room_id);
            $settlement = DB::table('contract_settlements')->where('contract_id', $contract->id)->lockForUpdate()->firstOrFail();
            if ($settlement->confirmation_state === 'CONFIRMED') {
                return;
            }
            if ($settlement->collection_policy !== 'NO_NEW_AUTO_RECEIVABLES' || DB::table('contract_deposit_entries')->where('contract_id', $contract->id)->where('event', 'FORFEITED')->exists()) {
                throw ValidationException::withMessages(['settlement' => 'Cần đối soát lịch sử cọc; không thể tự xác nhận số dư.']);
            }
            DB::table('contract_settlements')->where('id', $settlement->id)->update(['confirmation_state' => 'CONFIRMED', 'deposit_status' => 'NO_HELD_DEPOSIT_CONFIRMED', 'confirmed_by' => $actor, 'confirmed_at' => now(), 'confirmation_reason' => $reason, 'updated_at' => now()]);
        }, 3);
    }

    public function abandoned(int $contractId, string $period): bool
    {
        $end = DB::table('contract_terminations')->where('contract_id', $contractId)->where('reason', 'TENANT_ABANDONMENT')->first();
        if (! $end || $this->period($end->actual_ended_at) !== $period) {
            return false;
        }
        $case = DB::table('contract_settlements')->where('contract_id', $contractId)->first();
        if (! $case || $case->confirmation_state !== 'CONFIRMED') {
            throw ValidationException::withMessages(['settlement_confirmation' => 'Bỏ ngang chưa đủ xác nhận về cọc/bằng chứng. Cần chủ trọ xác nhận, không tự suy diễn.']);
        }

        return true;
    }

    /** Flexible utility cycles use (start, end] for a checkout boundary: the ending tenant owns usage through CHECK_OUT. */
    public function abandonedWithin(int $contractId, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $termination = DB::table('contract_terminations')->where('contract_id', $contractId)->where('reason', 'TENANT_ABANDONMENT')->first();
        if (! $termination || $termination->actual_ended_at <= $start->format('Y-m-d H:i:s') || $termination->actual_ended_at > $end->format('Y-m-d H:i:s')) {
            return false;
        }
        $case = DB::table('contract_settlements')->where('contract_id', $contractId)->first();
        if (! $case || $case->confirmation_state !== 'CONFIRMED') {
            throw ValidationException::withMessages(['settlement_confirmation' => 'Bỏ ngang chưa đủ xác nhận về cọc/bằng chứng. Cần chủ trọ xác nhận, không tự suy diễn.']);
        }

        return true;
    }

    public function assertInvoiceWriteAllowed(Invoice $invoice): void
    {
        if ($invoice->contract_id !== null && $this->abandoned($invoice->contract_id, $invoice->billing_period->toDateString())) {
            throw ValidationException::withMessages(['settlement_review' => 'TENANT_ABANDONMENT: không tạo thêm khoản phải thu trong kỳ quyết toán. Khoản nháp/nợ/chứng từ có sẵn được giữ nguyên và cần quy trình kiểm tra riêng.']);
        }
    }

    public function assertRoomReviewComplete(Room $room, string $period): void
    {
        $cases = DB::table('contract_settlements')->whereIn('contract_id', $room->contracts()->select('id'))->where('billing_period', $period)->where('collection_policy', 'NO_NEW_AUTO_RECEIVABLES')->get();
        foreach ($cases as $case) {
            $this->abandoned($case->contract_id, $period);
            if (Invoice::withTrashed()->where('contract_id', $case->contract_id)->whereDate('billing_period', $period)->where('status', '!=', 'CANCELLED')->exists()) {
                throw ValidationException::withMessages(['settlement_review' => 'Kỳ bỏ ngang đã có hóa đơn/khoản thu. Cần kiểm tra nguồn gốc và điều chỉnh có lịch sử, không tự xóa hoặc chuyển sang người mới.']);
            }
        }
    }

    public function reconcile(Room $room, string $period, CarbonImmutable $start, CarbonImmutable $end, array $calculations): void
    {
        $cases = DB::table('contract_settlements')->join('contract_terminations', 'contract_terminations.id', '=', 'contract_settlements.contract_termination_id')
            ->whereIn('contract_settlements.contract_id', $room->contracts()->select('id'))
            ->where('contract_terminations.actual_ended_at', '>', $start->format('Y-m-d H:i:s'))
            ->where('contract_terminations.actual_ended_at', '<=', $end->format('Y-m-d H:i:s'))
            ->select('contract_settlements.*')->get();
        foreach ($cases as $case) {
            foreach ($calculations as $calculation) {
                $quantity = BigDecimal::zero();
                $amount = BigDecimal::zero();
                foreach ($calculation['segments'] as $segment) {
                    if ((int) $segment['contract_id'] === (int) $case->contract_id) {
                        $quantity = $quantity->plus($segment['quantity']);
                        $amount = $amount->plus($segment['tariff_amount']);
                    }
                }
                DB::table('contract_settlement_usages')->where('contract_settlement_id', $case->id)->where('utility_meter_id', $calculation['meter_id'])->update([
                    'period_opening_checkpoint_id' => $calculation['start_checkpoint_id'],
                    'checkout_period_quantity' => (string) $quantity,
                    'final_period_quantity' => (string) $quantity, 'tariff_amount' => (string) $amount, 'status' => 'FINAL_RECONCILED', 'updated_at' => now(),
                ]);
            }
            if (! DB::table('contract_settlement_usages')->where('contract_settlement_id', $case->id)->where('status', 'PENDING_FINAL_SETTLEMENT')->exists()) {
                DB::table('contract_settlements')->where('id', $case->id)->update(['status' => 'FINAL_RECONCILED', 'updated_at' => now()]);
            }
        }
    }
}
