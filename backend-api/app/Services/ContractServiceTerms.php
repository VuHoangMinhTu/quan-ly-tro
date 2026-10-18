<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractService as Term;
use App\Models\Invoice;
use App\Models\Room;
use App\Models\Service;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContractServiceTerms
{
    public function __construct(private BoardingHouseAccessService $access) {}

    public function eligible(Room $room): Collection
    {
        return $room->services()->wherePivot('is_active', true)->where('services.is_active', true)
            ->where('services.boarding_house_id', $room->boarding_house_id)->with('priceTiers')->get();
    }

    /** Called only under the room/contract write lock, while still DRAFT. */
    public function replaceDraft(Contract $contract, Room $room, array $rows, ?int $actor): void
    {
        if ($contract->status !== 'DRAFT') {
            $this->fail('services', 'Chỉ được chỉnh trực tiếp dịch vụ của hợp đồng nháp.');
        }
        $eligible = $this->eligible($room)->keyBy('id');
        $ids = array_map(fn ($row) => (int) $row['service_id'], $rows);
        if (count($ids) !== count(array_unique($ids))) {
            $this->fail('services', 'Danh sách dịch vụ không được trùng lặp.');
        }
        $snapshots = [];
        foreach ($rows as $index => $row) {
            $service = $eligible->get($row['service_id']);
            if (! $service) {
                $this->fail("services.{$index}.service_id", 'Chỉ được chọn dịch vụ đang áp dụng cho phòng này.');
            }
            $snapshot = $this->snapshot($service, $row);
            $this->validatePricing($snapshot, "services.{$index}.");
            $snapshots[] = [...$snapshot, 'effective_from' => $contract->start_date?->format('Y-m-d'), 'created_by_user_id' => $actor];
        }
        $contract->agreedServices()->delete();
        $contract->agreedServices()->createMany($snapshots);
        $contract->service_terms_version = 1;
        $contract->save();
    }

    private function snapshot(Service $service, array $overrides): array
    {
        return [
            'service_id' => $service->id, 'service_name' => $service->name,
            'service_type' => $service->type, 'billing_method' => $service->billing_method, 'unit' => $service->unit,
            'base_price' => array_key_exists('base_price', $overrides) ? $overrides['base_price'] : $service->base_price,
            'vat_rate' => array_key_exists('vat_rate', $overrides) ? $overrides['vat_rate'] ?? 0 : $service->vat_rate ?? 0,
            'pricing_snapshot' => array_key_exists('pricing_snapshot', $overrides)
                ? $overrides['pricing_snapshot']
                : $service->priceTiers->map->only(['from_quantity', 'to_quantity', 'unit_price', 'tier_order'])->all(),
            'is_active' => true,
        ];
    }

    /** Validate complete contiguous tiers; billing must never silently undercharge gaps. */
    public function validatePricing(array $data, string $prefix = ''): void
    {
        if ($data['billing_method'] !== 'TIERED') {
            if ($data['base_price'] === null) {
                $this->fail($prefix.'base_price', 'Vui lòng nhập mức giá dịch vụ đã thỏa thuận.');
            }

            return;
        }
        $tiers = $data['pricing_snapshot'] ?? [];
        if ($tiers === []) {
            $this->fail($prefix.'pricing_snapshot', 'Vui lòng thiết lập đầy đủ bảng giá bậc thang.');
        }
        $next = BigDecimal::of(0);
        foreach ($tiers as $index => $tier) {
            $from = BigDecimal::of((string) $tier['from_quantity']);
            $to = $tier['to_quantity'] ?? null;
            if (! $from->isEqualTo($next)) {
                $this->fail($prefix.'pricing_snapshot', 'Các bậc giá phải liên tiếp từ 0, không chồng lấn hoặc bỏ khoảng.');
            }
            if ($to === null) {
                if ($index !== count($tiers) - 1) {
                    $this->fail($prefix.'pricing_snapshot', 'Bậc không giới hạn phải là bậc cuối cùng.');
                }
            } else {
                $next = BigDecimal::of((string) $to);
                if (! $next->isGreaterThan($from)) {
                    $this->fail($prefix.'pricing_snapshot', 'Giới hạn trên của bậc giá phải lớn hơn giới hạn dưới.');
                }
            }
        }
        if (($tiers[count($tiers) - 1]['to_quantity'] ?? null) !== null) {
            $this->fail($prefix.'pricing_snapshot', 'Bậc cuối cùng phải không giới hạn để tính đủ mức sử dụng.');
        }
    }

    public function finalize(Contract $contract, Room $room): void
    {
        $eligible = $this->eligible($room)->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach ($contract->agreedServices()->get() as $term) {
            if (! in_array((int) $term->service_id, $eligible, true)) {
                $this->fail('services', 'Một dịch vụ đã chọn không còn áp dụng cho phòng. Vui lòng kiểm tra hoặc đồng bộ lại trước khi kích hoạt.');
            }
            $this->validatePricing($term->toArray());
        }
        // Preserve the reviewed draft prices instead of re-reading the live price.
        $contract->agreedServices()->update(['effective_from' => $contract->start_date->format('Y-m-d')]);
        $contract->service_terms_version = 1;
    }

    public function listing(User $user, int $id): array
    {
        $contract = app(ContractService::class)->find($user, $id);
        $today = now()->toDateString();

        return [
            'legacy_fallback' => $contract->service_terms_version === null,
            'today' => $today,
            'terms' => $contract->agreedServices()->orderBy('effective_from')->orderBy('id')->get(),
            'available_services' => in_array($contract->status, ['DRAFT', 'ACTIVE'], true) ? $this->eligible($contract->room) : [],
        ];
    }

    /** All term writes serialize with activation and invoice generation on the room. */
    private function locked(User $user, int $id): Contract
    {
        $contract = app(ContractService::class)->find($user, $id);
        $room = $this->access->room($user, $contract->room_id);
        $this->access->house($user, $room->boarding_house_id, true);
        Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();

        return Contract::query()->lockForUpdate()->findOrFail($id);
    }

    public function draft(User $user, int $id, array $data): void
    {
        DB::transaction(function () use ($user, $id, $data): void {
            $contract = $this->locked($user, $id);
            $rows = ! empty($data['sync_from_room'])
                ? $this->eligible($contract->room)->map(fn ($service) => ['service_id' => $service->id])->all()
                : $data['services'];
            $this->replaceDraft($contract, $contract->room, $rows, $user->id);
        }, 3);
    }

    public function adjust(User $user, int $id, array $data): void
    {
        DB::transaction(function () use ($user, $id, $data): void {
            $contract = $this->locked($user, $id);
            $date = $this->adjustmentDate($contract, $data['effective_from']);
            $this->guardInvoices($contract, $date);
            $current = null;
            if (! empty($data['contract_service_id'])) {
                $current = $contract->agreedServices()->findOrFail($data['contract_service_id']);
                if (! $current->is_active) {
                    $this->fail('contract_service_id', 'Dịch vụ đã ngừng áp dụng. Vui lòng dùng Thêm dịch vụ để áp dụng lại từ kỳ mới.');
                }
                $this->assertAppendable($contract, $current, $date);
                $snapshot = Arr::only($current->toArray(), ['service_id', 'service_name', 'service_type', 'billing_method', 'unit', 'base_price', 'vat_rate', 'pricing_snapshot']);
                $snapshot = [...$snapshot, ...Arr::only($data, ['base_price', 'vat_rate', 'pricing_snapshot'])];
            } else {
                $service = $this->eligible($contract->room)->firstWhere('id', $data['service_id']);
                if (! $service) {
                    $this->fail('service_id', 'Chỉ được thêm dịch vụ đang áp dụng cho phòng này.');
                }
                $last = $contract->agreedServices()->where('service_id', $service->id)->orderByDesc('effective_from')->orderByDesc('id')->first();
                if ($last && ($date <= $last->effective_from?->format('Y-m-d') || ($last->is_active && $last->effective_to === null) || ($last->effective_to !== null && $last->effective_to->format('Y-m-d') >= $date))) {
                    $this->fail('service_id', 'Dịch vụ đã có điều khoản áp dụng trong kỳ này. Vui lòng chọn Điều chỉnh dịch vụ.');
                }
                if ($last && ! $last->is_active && $last->effective_to === null) {
                    $current = $last;
                }
                $snapshot = $this->snapshot($service, $data);
            }
            $this->validatePricing($snapshot);
            if ($current) {
                $current->update(['effective_to' => CarbonImmutable::parse($date)->subDay()->toDateString()]);
            }
            $contract->agreedServices()->create([
                ...$snapshot, 'is_active' => true, 'effective_from' => $date, 'effective_to' => null,
                'adjustment_reason' => $data['reason'], 'created_by_user_id' => $user->id,
            ]);
        }, 3);
    }

    public function stop(User $user, int $id, int $termId, array $data): void
    {
        DB::transaction(function () use ($user, $id, $termId, $data): void {
            $contract = $this->locked($user, $id);
            $date = $this->adjustmentDate($contract, $data['effective_from']);
            $term = $contract->agreedServices()->findOrFail($termId);
            if (! $term->is_active) {
                $this->fail('services', 'Dịch vụ này đã ngừng áp dụng.');
            }
            $this->assertAppendable($contract, $term, $date);
            $this->guardInvoices($contract, $date);
            // Keep the historical price and actor; stopping only closes its range.
            $term->update(['effective_to' => CarbonImmutable::parse($date)->subDay()->toDateString()]);
            $contract->agreedServices()->create([
                ...Arr::only($term->toArray(), ['service_id', 'service_name', 'service_type', 'billing_method', 'unit', 'base_price', 'vat_rate', 'pricing_snapshot']),
                'is_active' => false, 'effective_from' => $date, 'effective_to' => null,
                'adjustment_reason' => $data['reason'], 'created_by_user_id' => $user->id,
            ]);
        }, 3);
    }

    private function adjustmentDate(Contract $contract, string $date): string
    {
        if ($contract->status !== 'ACTIVE') {
            $this->fail('status', 'Chỉ được điều chỉnh dịch vụ của hợp đồng đang hiệu lực.');
        }
        if ($contract->service_terms_version === null) {
            $this->fail('services', 'Hợp đồng cũ chưa có điều khoản dịch vụ đã xác nhận. Vui lòng kiểm tra và lập thỏa thuận mới trước khi điều chỉnh.');
        }
        if (substr($date, 8, 2) !== '01') {
            $this->fail('effective_from', 'Điều chỉnh dịch vụ phải áp dụng từ ngày đầu tiên của kỳ tháng.');
        }
        if ($date < $contract->start_date?->format('Y-m-d') || ($contract->end_date && $date > $contract->end_date->format('Y-m-d'))) {
            $this->fail('effective_from', 'Kỳ áp dụng phải nằm trong thời hạn hợp đồng.');
        }

        return $date;
    }

    private function assertAppendable(Contract $contract, Term $term, string $date): void
    {
        $newer = $contract->agreedServices()->where('service_id', $term->service_id)->whereKeyNot($term->id)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->exists();
        if ($term->effective_from === null || $date <= $term->effective_from->format('Y-m-d') || $term->effective_to !== null || $newer) {
            $this->fail('effective_from', 'Kỳ mới phải sau kỳ bắt đầu của điều khoản cuối cùng và không được chồng lấn lịch sử.');
        }
    }

    private function guardInvoices(Contract $contract, string $date): void
    {
        $invoices = Invoice::query()->where('contract_id', $contract->id)->where('billing_period', '>=', $date)
            ->where('status', '!=', 'CANCELLED')->withCount('payments')->orderBy('id')->lockForUpdate()->get();
        if ($invoices->contains(fn ($invoice) => in_array($invoice->status, ['PAID', 'PARTIALLY_PAID'], true) || BigDecimal::of($invoice->paid_amount)->isGreaterThan(0) || $invoice->payments_count > 0)) {
            $this->fail('effective_from', 'Không thể điều chỉnh dịch vụ từ kỳ này vì đã có hóa đơn phát sinh thanh toán.');
        }
        if ($invoices->isNotEmpty()) {
            $this->fail('effective_from', 'Kỳ bị ảnh hưởng đã có hóa đơn. Vui lòng xử lý hóa đơn nháp hoặc hủy hóa đơn chưa thanh toán trước khi điều chỉnh dịch vụ.');
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
