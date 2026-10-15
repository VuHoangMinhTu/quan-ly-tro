<?php

namespace App\Services;

use App\Exceptions\InvoiceFinancialEditException;
use App\Models\Invoice;
use App\Models\Service;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BillingService
{
    public function generateForInvoice(Invoice $invoice): void
    {
        $oldRemaining = DB::transaction(function () use ($invoice): float {
            // Recheck persisted financial state under the same lock used by payments:
            // a previously loaded invoice may already have been paid by another request.
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if (! $invoice->canEditFinancials()) {
                throw new InvoiceFinancialEditException('Hóa đơn đã có thanh toán nên không thể chỉnh sửa các khoản thu.');
            }
            $oldRemaining = (float) $invoice->total_amount - (float) $invoice->paid_amount;
            $invoice->items()->where('source', 'AUTO')->delete();
            $start = $invoice->billing_period->copy()->startOfMonth();
            $end = $start->copy()->endOfMonth();
            if ($invoice->contract) {
                $c = $invoice->contract;
                if ($c->monthly_rent === null || $c->start_date === null) {
                    throw ValidationException::withMessages(['contract_id' => 'Hợp đồng chưa đủ ngày bắt đầu và giá thuê thỏa thuận. Vui lòng hoàn thiện hợp đồng trước khi tạo tiền phòng.']);
                }
                if ($c->room_id !== $invoice->room_id || $c->start_date > $end || ($c->end_date && $c->end_date < $start)) {
                    throw ValidationException::withMessages(['contract_id' => 'Hợp đồng không có hiệu lực trong kỳ hóa đơn này.']);
                } $this->item($invoice, 'RENT', 'Tiền phòng tháng '.$start->format('m/Y'), 1, $c->monthly_rent, $c->monthly_rent);
            }
            // The house catalogue is not a room subscription. Only explicitly
            // assigned, currently enabled services participate in this generation.
            $services = $invoice->room->services()
                ->wherePivot('is_active', true)
                ->where('services.is_active', true)
                ->where('services.boarding_house_id', $invoice->room->boarding_house_id)
                ->with('priceTiers')
                ->get();

            foreach ($services as $service) {
                if ($service->billing_method === 'FIXED' && $service->base_price !== null) {
                    $this->item($invoice, $service->type, $service->name, 1, $service->base_price, $service->base_price, $service);
                }
                if ($service->billing_method === 'PER_PERSON' && $service->base_price !== null) {
                    $count = $invoice->room->roomTenants()->where('status', 'ACTIVE')->whereDate('move_in_date', '<=', $end)->where(fn ($q) => $q->whereNull('move_out_date')->orWhereDate('move_out_date', '>=', $start))->count();
                    if ($count) {
                        $this->item($invoice, $service->type, $service->name, $count, $service->base_price, BigDecimal::of($service->base_price)->multipliedBy($count), $service);
                    }
                }
                if (in_array($service->billing_method, ['PER_UNIT', 'TIERED'], true)) {
                    $meter = $invoice->room->utilityMeters()->where('service_id', $service->id)->where('is_active', true)->first();
                    if (! $meter) {
                        throw ValidationException::withMessages(['service_id' => __('services.missing_meter', [
                            'type' => __('services.types.'.$service->type),
                            'method' => __('services.billing_methods.'.$service->billing_method),
                        ])]);
                    }
                    // readings() defaults to ascending order for history views;
                    // replace it here rather than appending a conflicting order.
                    $current = $meter->readings()->whereDate('reading_date', '<=', $end)->reorder('reading_date', 'desc')->first();
                    if (! $current) {
                        throw ValidationException::withMessages(['reading_date' => __('services.missing_reading', [
                            'type' => __('services.types.'.$service->type),
                        ])]);
                    }
                    $previous = $meter->readings()->whereDate('reading_date', '<', $start)->reorder('reading_date', 'desc')->first();
                    $consumption = BigDecimal::of($current->reading_value)->minus($previous ? $previous->reading_value : $meter->initial_reading)->strippedOfTrailingZeros();
                    if ($consumption->isLessThan(0)) {
                        throw ValidationException::withMessages(['reading_value' => 'Mức tiêu thụ điện/nước không được âm. Vui lòng kiểm tra lại chỉ số công tơ.']);
                    }
                    if ($service->billing_method === 'PER_UNIT') {
                        if ($service->base_price === null) {
                            throw ValidationException::withMessages(['service_id' => 'Dịch vụ chưa có đơn giá. Vui lòng cập nhật đơn giá trước khi tạo khoản thu.']);
                        }
                        $this->item($invoice, $service->type, $service->name.' '.$consumption, $consumption, $service->base_price, $consumption->multipliedBy($service->base_price), $service);
                    } else {
                        $tiers = $service->priceTiers;
                        if ($tiers->isEmpty()) {
                            throw ValidationException::withMessages(['service_id' => 'Dịch vụ chưa có bảng giá bậc thang. Vui lòng thêm bậc giá trước khi tạo khoản thu.']);
                        }
                        $amount = BigDecimal::of(0);
                        foreach ($tiers as $tier) {
                            $upper = $tier->to_quantity === null ? $consumption : BigDecimal::min($consumption, $tier->to_quantity);
                            $width = $upper->minus($tier->from_quantity);
                            if ($width->isGreaterThan(0)) {
                                $amount = $amount->plus($width->multipliedBy($tier->unit_price));
                            }
                        }
                        $this->item($invoice, $service->type, $service->name.' '.$consumption, $consumption, 0, $amount, $service);
                    }
                }
            }
            $invoice->recalculateTotals();

            return $oldRemaining;
        });

        $updatedInvoice = $invoice->fresh();
        app(PayOSService::class)->invalidateRequestsForAmountChange(
            $updatedInvoice,
            $oldRemaining,
            (float) $updatedInvoice->total_amount - (float) $updatedInvoice->paid_amount,
        );
    }

    private function item(Invoice $invoice, string $type, string $description, BigDecimal|int|string $quantity, BigDecimal|int|string $price, BigDecimal|int|string $amount, ?Service $service = null): void
    {
        $amount = BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp);
        $invoice->items()->create(['type' => $type, 'description' => $description, 'quantity' => (string) $quantity, 'unit_price' => (string) $price, 'amount' => (string) $amount, 'source' => 'AUTO']);
        $rate = BigDecimal::of($service?->vat_rate ?? 0);
        if ($rate->isZero()) {
            return;
        }

        // Tax is a service configuration, not a legal default. Snapshot the rate
        // and round half-up to a whole dong; regeneration replaces only AUTO items.
        $tax = $amount->multipliedBy($rate)->dividedBy(100, 0, RoundingMode::HalfUp);
        $label = mb_strtolower(__('services.types.'.$service->type));
        $rateLabel = str_contains((string) $rate, '.') ? rtrim(rtrim((string) $rate, '0'), '.') : (string) $rate;
        $invoice->items()->create([
            'type' => 'SERVICE_TAX', 'description' => 'VAT tiền '.$label.' ('.$rateLabel.'%)',
            'quantity' => 1, 'unit_price' => (string) $tax, 'amount' => (string) $tax,
            'source' => 'AUTO', 'tax_rate' => (string) $rate,
        ]);
    }
}
