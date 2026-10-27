<?php

namespace App\Services;

use App\Exceptions\InvoiceFinancialEditException;
use App\Models\ContractService as ContractTerm;
use App\Models\Invoice;
use App\Models\Room;
use App\Models\Service;
use App\Models\UtilityMeter;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BillingService
{
    public function generateForInvoice(Invoice $invoice): void
    {
        $oldRemaining = DB::transaction(function () use ($invoice): float {
            // Term changes and generation share this lock before touching invoices.
            Room::query()->whereKey($invoice->room_id)->lockForUpdate()->firstOrFail();
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
            $services = $this->servicesForInvoice($invoice);

            foreach ($services as $service) {
                $contractTerm = $service instanceof ContractTerm;
                $serviceId = $contractTerm ? $service->service_id : $service->id;
                $type = $contractTerm ? $service->service_type : $service->type;
                $name = $contractTerm ? $service->service_name : $service->name;
                if (in_array($service->billing_method, ['FIXED', 'PER_PERSON', 'PER_UNIT'], true) && $service->base_price === null) {
                    throw ValidationException::withMessages(['service_id' => "Dịch vụ {$name} chưa có đơn giá. Vui lòng cập nhật trước khi tạo khoản thu."]);
                }
                if ($service->billing_method === 'FIXED') {
                    $this->item($invoice, $type, $name, 1, $service->base_price, $service->base_price, $service);
                }
                if ($service->billing_method === 'PER_PERSON') {
                    $count = $invoice->room->roomTenants()->whereIn('status', ['ACTIVE', 'MOVED_OUT'])
                        ->when($contractTerm, fn ($query) => $query->where('contract_id', $invoice->contract_id))
                        ->whereDate('move_in_date', '<=', $end)->where(fn ($q) => $q->whereNull('move_out_date')->orWhereDate('move_out_date', '>=', $start))->distinct()->count('tenant_id');
                    if ($count) {
                        $this->item($invoice, $type, $name, $count, $service->base_price, BigDecimal::of($service->base_price)->multipliedBy($count), $service);
                    }
                }
                if (in_array($service->billing_method, ['PER_UNIT', 'TIERED'], true)) {
                    if ($serviceId === null) {
                        throw ValidationException::withMessages(['service_id' => 'Không xác định được dịch vụ nguồn của đồng hồ. Vui lòng kiểm tra thỏa thuận dịch vụ, không thể tự dùng đồng hồ khác.']);
                    }
                    $opening = $contractTerm ? $invoice->contract->utilityOpenings()->where('service_id', $serviceId)->first() : null;
                    $meter = $opening?->utility_meter_id
                        ? UtilityMeter::withTrashed()->where('room_id', $invoice->room_id)->where('service_id', $serviceId)->find($opening->utility_meter_id)
                        : $invoice->room->utilityMeters()->where('service_id', $serviceId)->where('is_active', true)->first();
                    if (! $meter) {
                        throw ValidationException::withMessages(['service_id' => __('services.missing_meter', [
                            'type' => __('services.types.'.$type),
                            'method' => __('services.billing_methods.'.$service->billing_method),
                        ])]);
                    }
                    // readings() defaults to ascending order for history views;
                    // replace it here rather than appending a conflicting order.
                    $previous = $meter->readings()->whereDate('reading_date', '<=', $start)
                        ->reorder('reading_date', 'desc')->orderByDesc('id')->first();
                    $openingDate = $previous?->reading_date?->format('Y-m-d');
                    $openingValue = $previous?->reading_value;
                    if ($opening && $opening->reading_date->format('Y-m-d') <= $start->format('Y-m-d')
                        && ($openingDate === null || $opening->reading_date->format('Y-m-d') > $openingDate)) {
                        $openingValue = $opening->opening_reading;
                        $openingDate = $opening->reading_date->format('Y-m-d');
                    }
                    $openingValue ??= $meter->initial_reading;
                    $current = $meter->readings()->whereDate('reading_date', '<=', $end)
                        ->whereDate('reading_date', $openingDate === null ? '>=' : '>', $openingDate ?? $start)
                        ->reorder('reading_date', 'desc')->orderByDesc('id')->first();
                    if (! $current) {
                        throw ValidationException::withMessages(['reading_date' => __('services.missing_closing_reading', [
                            'type' => $name, 'date' => $end->format('d/m/Y'),
                        ])]);
                    }
                    $consumption = BigDecimal::of($current->reading_value)->minus($openingValue)->strippedOfTrailingZeros();
                    if ($consumption->isLessThan(0)) {
                        throw ValidationException::withMessages(['reading_value' => 'Mức tiêu thụ điện/nước không được âm. Vui lòng kiểm tra lại chỉ số công tơ.']);
                    }
                    if ($service->billing_method === 'PER_UNIT') {
                        $this->item($invoice, $type, $name.' '.$consumption, $consumption, $service->base_price, $consumption->multipliedBy($service->base_price), $service);
                    } else {
                        $tiers = $contractTerm ? collect($service->pricing_snapshot ?? [])->map(fn ($tier) => (object) $tier) : $service->priceTiers;
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
                        $this->item($invoice, $type, $name.' '.$consumption, $consumption, 0, $amount, $service);
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

    private function servicesForInvoice(Invoice $invoice): Collection
    {
        $assigned = $invoice->room->services()->wherePivot('is_active', true)->where('services.is_active', true)
            ->where('services.boarding_house_id', $invoice->room->boarding_house_id)->with('priceTiers')->get();
        $contract = $invoice->contract;
        if ($contract && $contract->service_terms_version !== null) {
            $all = $contract->agreedServices()->get();
            $missingBaseline = collect($contract->signing_snapshot['services'] ?? [])->contains(fn ($saved) => isset($saved['id'])
                ? ! $all->contains('id', $saved['id'])
                : ! $all->contains('service_id', $saved['service_id'] ?? null));
            if ($missingBaseline || $all->contains(fn ($term) => $term->effective_from === null)) {
                throw ValidationException::withMessages(['services' => 'Điều khoản dịch vụ của hợp đồng chưa đầy đủ. Vui lòng kiểm tra trước khi tạo hóa đơn.']);
            }
            // Initial terms may start mid-month. Existing rent/usage rules charge
            // the monthly period; amendments themselves always start on day 01.
            $lookup = max($invoice->billing_period->format('Y-m-d'), $contract->start_date->format('Y-m-d'));
            $terms = $all->filter(fn ($term) => $term->effective_from->format('Y-m-d') <= $lookup
                && ($term->effective_to === null || $term->effective_to->format('Y-m-d') >= $lookup));
            if ($terms->groupBy(fn ($term) => $term->service_id ?? 'term-'.$term->id)->contains(fn ($group) => $group->count() > 1)) {
                throw ValidationException::withMessages(['services' => 'Khoảng hiệu lực dịch vụ bị chồng lấn. Vui lòng kiểm tra trước khi tạo hóa đơn.']);
            }
            if ($terms->where('is_active', true)->contains(fn ($term) => $term->service_id === null)) {
                throw ValidationException::withMessages(['service_id' => 'Không xác định được dịch vụ nguồn của điều khoản hợp đồng. Vui lòng kiểm tra trước khi tạo khoản thu.']);
            }
            $pricing = app(ContractServiceTerms::class);

            // The active room assignment gates billing. A matching contract term
            // supplies the agreed price; a newly assigned service uses its live price.
            return $assigned->map(function (Service $service) use ($terms, $all, $pricing) {
                $term = $terms->firstWhere('service_id', $service->id);
                if ($term?->is_active) {
                    $pricing->validatePricing($term->toArray());

                    return $term;
                }

                // A service governed by contract terms must respect its effective
                // dates, including periods before addition and after termination.
                return $all->contains('service_id', $service->id) ? null : $service;
            })->filter()->values();
        }

        return $assigned;
    }

    private function item(Invoice $invoice, string $type, string $description, BigDecimal|int|string $quantity, BigDecimal|int|string $price, BigDecimal|int|string $amount, Service|ContractTerm|null $service = null): void
    {
        $amount = BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp);
        $unit = match ($service?->billing_method) {
            'FIXED' => 'tháng', 'PER_PERSON' => 'người', default => $service?->unit ?? ($type === 'RENT' ? 'tháng' : null),
        };
        $invoice->items()->create(['type' => $type, 'description' => $description, 'quantity' => (string) $quantity,
            'unit' => $unit, 'unit_price' => (string) $price,
            'amount' => (string) $amount, 'source' => 'AUTO']);
    }
}
