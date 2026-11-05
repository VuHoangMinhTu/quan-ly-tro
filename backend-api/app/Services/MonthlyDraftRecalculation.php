<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Room;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MonthlyDraftRecalculation
{
    public function preview(Room $room, string $period, string $usageStartAt, string $usageEndAt, array $confirmations, int $actor): array
    {
        try {
            return DB::transaction(function () use ($room, $period, $usageStartAt, $usageEndAt, $confirmations, $actor): array {
                $this->lock($room, $period, $actor);
                $before = $this->state($room, $period, true);
                $candidate = $this->simulate($room, $period, $usageStartAt, $usageEndAt, $confirmations, $actor);
                $token = (string) Str::uuid();
                $id = DB::table('monthly_draft_recalculations')->insertGetId(['token' => $token, 'room_id' => $room->id, 'billing_period' => $period,
                    'usage_start_at' => $usageStartAt, 'usage_end_at' => $usageEndAt,
                    'status' => 'PREVIEWED', 'before_hash' => $this->hash($before), 'candidate_hash' => $this->hash($candidate),
                    'before_snapshot' => json_encode($before, JSON_THROW_ON_ERROR), 'candidate_snapshot' => json_encode($candidate, JSON_THROW_ON_ERROR),
                    'confirmations' => json_encode($confirmations, JSON_THROW_ON_ERROR), 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);

                return ['status' => 'PREVIEWED', 'token' => $token, 'history_id' => $id, 'before' => $before, 'after' => $candidate];
            }, 3);
        } catch (ValidationException $error) {
            return ['status' => isset($error->errors()['checkpoints']) ? 'PENDING_FINAL_SETTLEMENT' : 'BLOCKED', 'errors' => $error->errors()];
        }
    }

    public function confirm(Room $room, string $token, string $reason, int $actor): array
    {
        return DB::transaction(function () use ($room, $token, $reason, $actor): array {
            Room::query()->lockForUpdate()->findOrFail($room->id);
            $record = DB::table('monthly_draft_recalculations')->where('room_id', $room->id)->where('token', $token)->where('created_by', $actor)->lockForUpdate()->firstOrFail();
            if ($record->status === 'APPLIED') {
                $this->check($record->reason === $reason, 'token', 'Lần xác nhận đã dùng lý do khác; không áp dụng lại.');

                return $this->result($record, true);
            }
            $this->check($record->usage_start_at !== null && $record->usage_end_at !== null, 'token', 'Bản xem trước cũ thiếu kỳ sử dụng; hãy xem trước lại với thời gian bắt đầu và kết thúc.');
            $this->lock($room, $record->billing_period, $actor);
            $this->check($this->hash($this->state($room, $record->billing_period, true)) === $record->before_hash, 'stale_preview', 'Hóa đơn đã thay đổi sau khi xem trước. Hãy xem trước lại.');
            $confirmations = json_decode($record->confirmations, true, 512, JSON_THROW_ON_ERROR);
            $candidate = $this->simulate($room, $record->billing_period, $record->usage_start_at, $record->usage_end_at, $confirmations, $actor);
            $this->check($this->hash($candidate) === $record->candidate_hash, 'stale_preview', 'Dữ liệu nguồn đã thay đổi. Hãy xem trước chênh lệch mới trước khi xác nhận.');
            app(MonthlyInvoiceBilling::class)->rebuildDrafts($room, $record->billing_period, $record->usage_start_at, $record->usage_end_at, $confirmations, $actor);
            $this->check($this->hash($this->state($room, $record->billing_period, false)) === $record->candidate_hash, 'stale_preview', 'Kết quả không khớp bản xem trước; toàn bộ cập nhật đã hủy.');
            DB::table('monthly_draft_recalculations')->where('id', $record->id)->update(['status' => 'APPLIED', 'approved_by' => $actor, 'reason' => $reason, 'approved_at' => now(), 'updated_at' => now()]);

            return $this->result(DB::table('monthly_draft_recalculations')->find($record->id), false);
        }, 3);
    }

    private function result(object $record, bool $replayed): array
    {
        return ['status' => 'UPDATED', 'replayed' => $replayed, 'history_id' => $record->id, 'approved_by' => $record->approved_by, 'approved_at' => $record->approved_at, 'reason' => $record->reason,
            'before' => json_decode($record->before_snapshot, true), 'after' => json_decode($record->candidate_snapshot, true)];
    }

    private function lock(Room $room, string $period, int $actor): void
    {
        $locked = Room::query()->lockForUpdate()->findOrFail($room->id);
        $this->check((int) $locked->boardingHouse->landlord->user_id === $actor, 'owner', 'Chỉ chủ trọ sở hữu được cập nhật hóa đơn.');
        $invoices = Invoice::withTrashed()->where('room_id', $room->id)->whereDate('billing_period', $period)->orderBy('id')->lockForUpdate()->get();
        $composedIds = DB::table('monthly_invoice_compositions')->whereIn('invoice_id', $invoices->pluck('id'))->pluck('invoice_id')->map(fn ($id) => (int) $id)->all();
        $this->check($invoices->isNotEmpty() && $invoices->every(fn (Invoice $invoice) => in_array((int) $invoice->id, $composedIds, true) || app(MonthlyInvoiceBilling::class)->isUtilityOnlyInvoice($invoice)), 'invoice_review', 'Cần đối soát hóa đơn tháng trước khi cập nhật.');
        foreach ($invoices as $invoice) {
            $this->check(! $invoice->deleted_at && $invoice->status === 'DRAFT' && ! $invoice->issued_at && BigDecimal::of($invoice->paid_amount)->isZero() && ! $invoice->payments()->exists() && ! $invoice->payosPaymentRequests()->exists(), 'invoice_review', 'Chỉ cập nhật DRAFT chưa phát hành, chưa thanh toán và chưa liên quan yêu cầu thanh toán.');
        }
    }

    /** Reuse the exact writer inside a savepoint; no preview money/item change commits. */
    private function simulate(Room $room, string $period, string $usageStartAt, string $usageEndAt, array $confirmations, int $actor): array
    {
        DB::beginTransaction();
        try {
            app(MonthlyInvoiceBilling::class)->rebuildDrafts($room, $period, $usageStartAt, $usageEndAt, $confirmations, $actor);

            return $this->state($room, $period, false);
        } finally {
            DB::rollBack();
        }
    }

    private function state(Room $room, string $period, bool $identities): array
    {
        $invoices = Invoice::withTrashed()->where('room_id', $room->id)->whereDate('billing_period', $period)->orderBy('id')->get()->map(function ($invoice) use ($identities) {
            $items = $invoice->items()->orderBy('id')->get()->map(function ($item) use ($identities) {
                $data = $item->only(['type', 'description', 'quantity', 'unit', 'unit_price', 'amount', 'source', 'tax_rate']);
                if ($identities || $item->source === 'MANUAL') {
                    $data['id'] = $item->id;
                }

                return $data;
            })->sortBy(fn ($item) => json_encode($item))->values()->all();
            $composition = DB::table('monthly_invoice_compositions')->where('invoice_id', $invoice->id)->first();

            return [...$invoice->only(['id', 'contract_id', 'invoice_code', 'billing_period', 'status', 'issued_at', 'subtotal', 'discount_amount', 'total_amount', 'paid_amount', 'note']), 'items' => $items,
                'evidence' => $composition ? json_decode($composition->snapshot, true) : null, 'confirmation' => $composition ? json_decode($composition->confirmation, true) : null];
        })->all();
        $cycles = DB::table('utility_billing_periods')->whereIn('utility_meter_id', $room->utilityMeters()->withTrashed()->select('id'))->where('billing_period', $period)->orderBy('utility_meter_id')->get();

        return ['invoices' => $invoices, 'meters' => $cycles->map(fn ($cycle) => json_decode($cycle->snapshot, true))->all(),
            'allocations' => DB::table('utility_charge_allocations')->whereIn('utility_billing_period_id', $cycles->pluck('id'))->orderBy('segment_order')->get()->map(function ($allocation) use ($identities) {
                $data = (array) $allocation;
                if (! $identities) {
                    unset($data['id'], $data['utility_billing_period_id'], $data['invoice_item_id']);
                }

                return $data;
            })->sortBy(fn ($row) => json_encode($row))->values()->all()];
    }

    private function hash(array $state): string
    {
        $normalize = function (array $value) use (&$normalize): array {
            unset($value['calculated_at']);
            foreach ($value as $key => $entry) {
                if (is_array($entry)) {
                    $value[$key] = $normalize($entry);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($normalize($state), JSON_THROW_ON_ERROR));
    }

    private function check(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
