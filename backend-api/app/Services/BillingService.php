<?php

namespace App\Services;

use App\Exceptions\InvoiceFinancialEditException;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BillingService
{
    public function generateForInvoice(Invoice $invoice): void
    {
        if (! $invoice->canEditFinancials()) {
            throw new InvoiceFinancialEditException('Hóa đơn đã có thanh toán nên không thể chỉnh sửa các khoản thu.');
        }
        $oldRemaining = (float) $invoice->total_amount - (float) $invoice->paid_amount;
        DB::transaction(function () use ($invoice) {
            $invoice->items()->where('source', 'AUTO')->delete();
            $start = $invoice->billing_period->copy()->startOfMonth();
            $end = $start->copy()->endOfMonth();
            if ($invoice->contract) {
                $c = $invoice->contract;
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
                    $this->item($invoice, $service->type, $service->name, 1, $service->base_price, $service->base_price);
                }
                if ($service->billing_method === 'PER_PERSON' && $service->base_price !== null) {
                    $count = $invoice->room->roomTenants()->where('status', 'ACTIVE')->whereDate('move_in_date', '<=', $end)->where(fn ($q) => $q->whereNull('move_out_date')->orWhereDate('move_out_date', '>=', $start))->count();
                    if ($count) {
                        $this->item($invoice, $service->type, $service->name, $count, $service->base_price, $count * $service->base_price);
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
                    $consumption = $current->reading_value - ($previous ? $previous->reading_value : $meter->initial_reading);
                    if ($consumption < 0) {
                        throw ValidationException::withMessages(['reading_value' => 'Mức tiêu thụ điện/nước không được âm. Vui lòng kiểm tra lại chỉ số công tơ.']);
                    }
                    if ($service->billing_method === 'PER_UNIT') {
                        if ($service->base_price === null) {
                            throw ValidationException::withMessages(['service_id' => 'Dịch vụ chưa có đơn giá. Vui lòng cập nhật đơn giá trước khi tạo khoản thu.']);
                        }
                        $this->item($invoice, $service->type, $service->name.' '.$consumption, $consumption, $service->base_price, $consumption * $service->base_price);
                    } else {
                        $tiers = $service->priceTiers;
                        if ($tiers->isEmpty()) {
                            throw ValidationException::withMessages(['service_id' => 'Dịch vụ chưa có bảng giá bậc thang. Vui lòng thêm bậc giá trước khi tạo khoản thu.']);
                        }
                        $amount = 0;
                        foreach ($tiers as $tier) {
                            $width = $tier->to_quantity === null ? $consumption - $tier->from_quantity : min($consumption, $tier->to_quantity) - $tier->from_quantity;
                            if ($width > 0) {
                                $amount += $width * $tier->unit_price;
                            }
                        }
                        $this->item($invoice, $service->type, $service->name.' '.$consumption, $consumption, 0, $amount);
                    }
                }
            }
            $invoice->recalculateTotals();
        });

        $updatedInvoice = $invoice->fresh();
        app(PayOSService::class)->invalidateRequestsForAmountChange(
            $updatedInvoice,
            $oldRemaining,
            (float) $updatedInvoice->total_amount - (float) $updatedInvoice->paid_amount,
        );
    }

    private function item(Invoice $invoice, string $type, string $description, $quantity, $price, $amount): void
    {
        $invoice->items()->create(['type' => $type, 'description' => $description, 'quantity' => $quantity, 'unit_price' => $price, 'amount' => $amount, 'source' => 'AUTO']);
    }
}
