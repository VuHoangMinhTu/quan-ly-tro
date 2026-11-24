<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Room;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Physical deletion of a never-issued, never-paid invoice.
 * Keep confirmed meter checkpoints/boundaries. If the invoice has a V2
 * utility cycle, remove its derived calculations so creating the period again
 * regenerates invoice obligations and allocated items together.
 */
final class DraftInvoiceHardDeletion
{
    public function delete(Invoice $target): void
    {
        DB::transaction(function () use ($target): void {
            // Writers of monthly drafts and bed invoices use the same room lock.
            $room = Room::query()->lockForUpdate()->findOrFail($target->room_id);
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($target->id);

            $this->ensure($invoice->room_id === $room->id && $invoice->status === 'DRAFT'
                && $invoice->issued_at === null && $invoice->paid_at === null,
                'status', 'Chỉ xóa được hóa đơn nháp chưa phát hành.');
            $this->ensure(BigDecimal::of((string) $invoice->paid_amount)->isZero()
                && ! $invoice->payments()->exists() && ! $invoice->payosPaymentRequests()->exists(),
                'payments', 'Hóa đơn đã có giao dịch hoặc yêu cầu PayOS; phải đối soát, không được xóa.');

            app(ContractSettlementPolicy::class)->assertInvoiceWriteAllowed($invoice);
            $period = $invoice->billing_period->format('Y-m-d');
            $obligation = DB::table('invoice_obligations')->where('invoice_id', $invoice->id)->first();
            $composition = DB::table('monthly_invoice_compositions')->where('invoice_id', $invoice->id)->first();
            $isV2 = $obligation !== null || $composition !== null;
            $itemIds = $invoice->items()->pluck('id');
            $allocations = DB::table('utility_charge_allocations')
                ->whereIn('invoice_item_id', $itemIds)->get(['id', 'utility_billing_period_id']);

            if ($room->room_type === 'PRIVATE_ROOM' && $isV2) {
                // Unlike KTX beds, a private room's meter cycles can be shared
                // across multiple sequential tenants in one billing month.
                $siblingsExist = Invoice::query()->where('room_id', $room->id)
                    ->whereDate('billing_period', $period)->whereKeyNot($invoice->id)->exists();
                $this->ensure(! $siblingsExist, 'invoice_review',
                    'Kỳ điện nước của phòng có hóa đơn khác. Không thể xóa riêng một bản nháp; cần đối soát cả kỳ để tránh ảnh hưởng người thuê khác.');

                $periodIds = DB::table('utility_billing_periods')
                    ->where('billing_period', $period)
                    ->whereIn('utility_meter_id', $room->utilityMeters()->withTrashed()->select('id'))
                    ->pluck('id');
                $this->ensure($allocations->every(fn ($row) => $periodIds->contains($row->utility_billing_period_id)),
                    'invoice_review', 'Khoản điện nước đang liên kết với kỳ khác; không thể tự xóa.');
                $this->ensure(! DB::table('utility_charge_allocations')
                    ->whereIn('utility_billing_period_id', $periodIds)->whereNotNull('supplier_cost')->exists(),
                    'invoice_review', 'Kỳ đã có giá vốn điện nước; cần đối soát trước khi xóa.');
                // These are derived, replaceable calculations, not physical
                // readings. Keep utility_checkpoints and period_boundaries.
                DB::table('utility_charge_allocations')->whereIn('utility_billing_period_id', $periodIds)->delete();
                DB::table('utility_billing_periods')->whereIn('id', $periodIds)->delete();
            } else {
                $this->ensure($allocations->isEmpty(), 'invoice_review',
                    'Hóa đơn có phân bổ công tơ không thuộc luồng phòng riêng; cần đối soát trước khi xóa.');
            }

            // Preview/recalculation snapshots may contain the old invoice ID.
            // Invalidate just those belonging to this invoice, not another bed.
            $rows = DB::table('monthly_draft_recalculations')
                ->where('room_id', $room->id)->where('billing_period', $period)
                ->lockForUpdate()->get(['id', 'before_snapshot', 'candidate_snapshot']);
            foreach ($rows as $row) {
                if ($this->containsInvoice($row->before_snapshot, $invoice->id)
                    || $this->containsInvoice($row->candidate_snapshot, $invoice->id)) {
                    DB::table('monthly_draft_recalculations')->where('id', $row->id)->delete();
                }
            }

            DB::table('monthly_invoice_compositions')->where('invoice_id', $invoice->id)->delete();
            DB::table('invoice_obligations')->where('invoice_id', $invoice->id)->delete();
            $invoice->items()->delete();
            $invoice->forceDelete();
        }, 3);
    }

    private function containsInvoice(?string $snapshot, int $invoiceId): bool
    {
        if (! $snapshot) {
            return false;
        }
        $state = json_decode($snapshot, true);
        foreach (($state['invoices'] ?? []) as $invoice) {
            if ((int) ($invoice['id'] ?? 0) === $invoiceId) {
                return true;
            }
        }

        return false;
    }

    private function ensure(bool $valid, string $field, string $message): void
    {
        if (! $valid) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
