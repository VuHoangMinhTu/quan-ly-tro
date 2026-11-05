<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Room;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * KTX: one contract, one bed, one monthly anniversary cycle, one invoice.
 * Never consult another bed's billing dates, invoices, or settlement state.
 */
final class DormitoryBedBilling
{
    private const ENGINE = 'DORM_BED_V3';

    public function cycle(Contract $contract, string $period): array
    {
        $origin = $contract->start_date;
        $this->check($origin !== null, 'start_date', 'Hợp đồng chưa có ngày bắt đầu.');
        $month = CarbonImmutable::createFromFormat('!Y-m-d', $period, 'Asia/Ho_Chi_Minh');
        $this->check($month !== false && $month->format('Y-m-d') === $period && $month->day === 1,
            'billing_period', 'Tháng hóa đơn không hợp lệ.');
        $base = $month->startOfMonth();
        $next = $base->addMonthNoOverflow();
        $day = (int) $origin->format('d');
        $start = $base->day(min($day, $base->daysInMonth));
        $end = $next->day(min($day, $next->daysInMonth));
        $this->check($start->greaterThanOrEqualTo($origin->copy()->startOfDay()), 'billing_period',
            'Tháng hóa đơn trước ngày bắt đầu hợp đồng.');

        return [$start, $end];
    }

    public function generate(Contract $contract, string $period, array $confirmation, int $actor): array
    {
        return DB::transaction(function () use ($contract, $period, $confirmation, $actor): array {
            $room = Room::query()->lockForUpdate()->findOrFail($contract->room_id);
            $contract = Contract::query()->lockForUpdate()->findOrFail($contract->id);
            $this->check($room->room_type === 'DORMITORY' && $contract->contract_type === 'DORM_BED'
                && $contract->bed_id !== null && $room->beds()->whereKey($contract->bed_id)->exists(),
                'bed_id', 'Hợp đồng phải thuộc một giường KTX hợp lệ.');
            $this->check(in_array($contract->status, ['ACTIVE', 'TERMINATED', 'EXPIRED'], true),
                'status', 'Chỉ tạo hóa đơn cho hợp đồng đã kích hoạt.');
            $this->check((int) $contract->tenant?->landlord_id === (int) $room->boardingHouse->landlord_id,
                'contract_id', 'Người thuê không thuộc chủ trọ.');
            [$start, $end] = $this->cycle($contract, $period);
            $this->check($contract->handoverReports()->where('type', 'CHECK_IN')->where('status', 'CONFIRMED')->exists(),
                'contract_id', 'Hợp đồng chưa có bàn giao nhận phòng được xác nhận.');
            $this->check(! app(ContractSettlementPolicy::class)->abandonedWithin($contract->id, $start, $end),
                'settlement_review', 'Hợp đồng bỏ ngang cần đối soát riêng; không tự sinh tiền giường mới.');
            $termination = DB::table('contract_terminations')->where('contract_id', $contract->id)->first();
            $effectiveEnd = $termination?->actual_ended_at;
            $this->check($effectiveEnd === null || $effectiveEnd > $start->format('Y-m-d H:i:s'),
                'billing_period', 'Hợp đồng đã kết thúc trước kỳ thu này.');
            $this->check(!$contract->end_date || $contract->end_date->greaterThanOrEqualTo($start),
                'billing_period', 'Hợp đồng đã hết hạn trước kỳ thu này.');
            $partial = ($effectiveEnd && $effectiveEnd < $end->format('Y-m-d H:i:s'))
                || ($contract->end_date && $contract->end_date->format('Y-m-d') < $end->subDay()->format('Y-m-d'));
            $existing = Invoice::withTrashed()->where('room_id', $room->id)->where('contract_id', $contract->id)
                ->whereDate('billing_period', $period)->orderBy('id')->lockForUpdate()->get();
            if ($existing->isNotEmpty()) {
                $invoice = $existing->first();
                $record = DB::table('monthly_invoice_compositions')->where('invoice_id', $invoice->id)->first();
                // Never alter issued, paid, legacy, cancelled or deleted documents here.
                $this->check($existing->count() === 1 && !$invoice->deleted_at && $record !== null,
                    'invoice_review', 'Hợp đồng đã có hóa đơn trong kỳ; cần đối soát, không tự tạo trùng.');
                $snapshot = json_decode($record->snapshot, true);
                $this->check(($snapshot['engine'] ?? null) === self::ENGINE,
                    'invoice_review', 'Hóa đơn cũ được giữ nguyên; không tự chuyển sang quy tắc KTX mới.');
                $savedConfirmation = json_decode($record->confirmation, true) ?? [];
                $this->check($confirmation === [] || $confirmation == $savedConfirmation,
                    'confirmations', 'Hóa đơn đã tồn tại với thỏa thuận khác; hãy dùng tính lại bản nháp.');
                if ($invoice->status === 'DRAFT' && !$invoice->issued_at
                    && BigDecimal::of($invoice->paid_amount)->isZero() && !$invoice->payments()->exists()
                    && !$invoice->payosPaymentRequests()->exists()) {
                    $this->assertSourcesCurrent($invoice, $record, $snapshot);
                }
                return ['status' => 'EXISTING', 'room_id' => $room->id, 'contract_id' => $contract->id,
                    'invoices' => [$invoice->load('items')]];
            }
            $this->check(! DB::table('invoice_obligations')->where('room_id', $room->id)
                ->where('contract_id', $contract->id)->where('billing_period', $period)->exists(),
                'invoice_review', 'Đã tồn tại nghĩa vụ thu của hợp đồng trong kỳ.');
            $source = $this->source($contract);
            $lines = $this->lines($contract, $room, $start, $end, (bool) $partial, $confirmation);
            $invoice = $room->invoices()->create(['contract_id' => $contract->id, 'billing_period' => $period,
                'status' => 'DRAFT', 'discount_amount' => 0,
                'note' => 'Tiền giường KTX theo hợp đồng — kiểm tra trước khi phát hành.']);
            DB::table('invoice_obligations')->insert(['room_id' => $room->id, 'contract_id' => $contract->id,
                'billing_period' => $period, 'kind' => self::ENGINE, 'invoice_id' => $invoice->id]);
            foreach ($lines as $line) $invoice->items()->create($line);
            $invoice->recalculateTotals();
            DB::table('monthly_invoice_compositions')->insert(['invoice_id' => $invoice->id,
                'contract_id' => $contract->id, 'billing_period' => $period,
                'snapshot' => json_encode(['engine' => self::ENGINE, 'lines' => $lines,
                    'sources' => $source, 'usage_window' => ['start' => $start->format('Y-m-d H:i:s'),
                        'end' => $end->format('Y-m-d H:i:s'), 'timezone' => 'Asia/Ho_Chi_Minh'],
                    'usage_periods' => []], JSON_THROW_ON_ERROR),
                'confirmation' => json_encode($confirmation, JSON_THROW_ON_ERROR),
                'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);

            return ['status' => 'CREATED', 'room_id' => $room->id, 'contract_id' => $contract->id,
                'invoices' => [$invoice->fresh()->load('items')]];
        }, 3);
    }

    /** Called only by the existing preview/confirm coordinator inside its room transaction. */
    public function rebuildDraft(Invoice $invoice, array $confirmation, int $actor): void
    {
        $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
        $contract = Contract::with(['room', 'bed'])->findOrFail($invoice->contract_id);
        $room = $contract->room;
        $record = DB::table('monthly_invoice_compositions')->where('invoice_id', $invoice->id)->first();
        $old = $record ? json_decode($record->snapshot, true) : [];
        $this->check(($old['engine'] ?? null) === self::ENGINE && $room->room_type === 'DORMITORY',
            'invoice_review', 'Chỉ cập nhật hóa đơn giường KTX thuộc luồng mới.');
        $this->check($invoice->status === 'DRAFT' && !$invoice->issued_at
            && BigDecimal::of($invoice->paid_amount)->isZero()
            && !$invoice->payments()->exists() && !$invoice->payosPaymentRequests()->exists(),
            'invoice_review', 'Chỉ cập nhật bản nháp chưa phát hành và chưa có giao dịch thanh toán.');
        [$start, $end] = $this->cycle($contract, $invoice->billing_period->format('Y-m-d'));
        $this->check(! app(ContractSettlementPolicy::class)->abandonedWithin($contract->id, $start, $end),
            'settlement_review', 'Hợp đồng bỏ ngang phải đối soát; không tự phát sinh tiền giường mới.');
        $termination = DB::table('contract_terminations')->where('contract_id', $contract->id)->first();
        $partial = ($termination && $termination->actual_ended_at < $end->format('Y-m-d H:i:s'))
            || ($contract->end_date && $contract->end_date->format('Y-m-d') < $end->subDay()->format('Y-m-d'));
        $lines = $this->lines($contract, $room, $start, $end, (bool) $partial, $confirmation);
        $invoice->items()->where('source', 'AUTO')->delete();
        foreach ($lines as $line) $invoice->items()->create($line);
        $invoice->recalculateTotals();
        DB::table('monthly_invoice_compositions')->where('invoice_id', $invoice->id)->update([
            'snapshot' => json_encode(['engine' => self::ENGINE, 'lines' => $lines,
                'sources' => $this->source($contract), 'usage_window' => [
                    'start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s'),
                    'timezone' => 'Asia/Ho_Chi_Minh'], 'usage_periods' => []], JSON_THROW_ON_ERROR),
            'confirmation' => json_encode($confirmation, JSON_THROW_ON_ERROR), 'updated_at' => now(),
        ]);
    }

    /** Revalidate only this contract at issuance, not all other beds in its room. */
    public function assertSourcesCurrent(Invoice $invoice, object $record, array $snapshot): void
    {
        $contract = Contract::with(['room', 'bed'])->findOrFail($invoice->contract_id);
        $this->check($contract->room->room_type === 'DORMITORY'
            && $contract->contract_type === 'DORM_BED' && $contract->bed_id !== null,
            'stale_monthly_sources', 'Hợp đồng thuê giường đã thay đổi.');
        [$start, $end] = $this->cycle($contract, $record->billing_period);
        $this->check($contract->handoverReports()->where('type', 'CHECK_IN')->where('status', 'CONFIRMED')->exists(),
            'stale_monthly_sources', 'Thiếu xác nhận nhận giường.');
        $this->check($contract->monthly_rent !== null && $contract->bed !== null,
            'stale_monthly_sources', 'Thiếu thông tin tiền hoặc giường theo hợp đồng.');
        $this->check(! app(ContractSettlementPolicy::class)->abandonedWithin($contract->id, $start, $end),
            'stale_monthly_sources', 'Hợp đồng bỏ ngang cần đối soát trước khi phát hành.');
        $termination = DB::table('contract_terminations')->where('contract_id', $contract->id)->first();
        $partial = ($termination && $termination->actual_ended_at < $end->format('Y-m-d H:i:s'))
            || ($contract->end_date && $contract->end_date->format('Y-m-d') < $end->subDay()->format('Y-m-d'));
        $confirmation = json_decode($record->confirmation, true) ?? [];
        $lines = $this->lines($contract, $contract->room, $start, $end, (bool) $partial, $confirmation);
        $window = ['start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s'),
            'timezone' => 'Asia/Ho_Chi_Minh'];
        $this->check($this->source($contract) == ($snapshot['sources'] ?? [])
            && $lines == ($snapshot['lines'] ?? [])
            && $window == ($snapshot['usage_window'] ?? [])
            && ($snapshot['usage_periods'] ?? []) === [], 'stale_monthly_sources',
            'Tiền giường hoặc điều khoản hợp đồng đã thay đổi; cần đối soát hóa đơn nháp.');
        $savedAuto = $invoice->items()->where('source', 'AUTO')->orderBy('id')->get();
        $this->check($savedAuto->count() === count($lines), 'stale_monthly_sources',
            'Các khoản thu tự động không còn khớp hóa đơn gốc.');
        foreach ($lines as $index => $line) {
            $item = $savedAuto[$index];
            $this->check($item->type === $line['type'] && $item->description === $line['description']
                && BigDecimal::of($item->amount)->isEqualTo($line['amount']), 'stale_monthly_sources',
                'Tiền trên hóa đơn đã thay đổi; cần kiểm tra trước khi phát hành.');
        }
    }

    private function source(Contract $contract): array
    {
        return ['contract_id' => $contract->id, 'bed_id' => $contract->bed_id,
            'contract_start' => $contract->start_date->format('Y-m-d'),
            'agreed_monthly_rent' => $contract->monthly_rent,
            'service_terms_version' => $contract->service_terms_version,
            'terms' => $contract->agreedServices()->get()->toArray()];
    }

    private function lines(Contract $contract, Room $room, CarbonImmutable $start, CarbonImmutable $end,
        bool $partial, array $confirmation): array
    {
        $lines = app(MonthlyInvoiceBilling::class)->buildDormitoryLines($contract, $room, $start, $end,
            $partial, $confirmation);
        $lines[0]['description'] = 'Tiền giường '.$contract->bed->bed_code.' · '
            .$start->format('d/m/Y').' - '.$end->format('d/m/Y');
        return $lines;
    }

    private function check(bool $condition, string $field, string $message): void
    {
        if (!$condition) throw ValidationException::withMessages([$field => $message]);
    }
}
