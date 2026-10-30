<?php

namespace App\Services;

use App\Models\ContractHandoverReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContractHandoverService
{
    public const ROOM_RENTAL_ITEMS = [
        'Phòng mới không có lỗ đinh, Tường trắng sạch sẽ',
        'Ballet, nệm.',
        'Thiết bị nhà vệ sinh',
        'Tivi',
        'Bàn ghế, sofa.',
        'Tủ quần áo, giá treo đồ',
        'Cục phát wifi',
        'Khóa cửa',
        'Máy lạnh',
        'Tủ lạnh',
        'Máy nước nóng',
        'Rèm cửa',
        'Tủ, kệ bếp',
    ];

    public const DORM_BED_ITEMS = [
        'Khung giường, vạt giường, rèm che riêng tư',
        'Nệm nằm, drap/ga bọc nệm (nếu có)',
        'Tủ quần áo/tủ đồ cá nhân + chìa khóa',
        'Đèn đọc sách tại giường, ổ cắm điện',
        'Khóa vân tay / Thẻ từ / Chìa khóa cửa chính KTX',
        'Máy lạnh phòng + Remote điều hòa',
    ];

    public function __construct(private ContractService $contracts) {}

    public function find(User $user, int $contractId): ?ContractHandoverReport
    {
        return $this->contracts->find($user, $contractId)->handoverReports()->where('type', 'CHECK_IN')->first();
    }

    public function create(User $user, int $contractId): void
    {
        DB::transaction(function () use ($user, $contractId): void {
            $contract = $this->contracts->find($user, $contractId);
            $contract = $contract->newQuery()->lockForUpdate()->findOrFail($contractId);
            if ($contract->handoverReports()->where('type', 'CHECK_IN')->exists()) {
                throw ValidationException::withMessages(['report' => 'Biên bản CHECK_IN đã tồn tại.']);
            }
            $defaults = $contract->contract_type === 'DORM_BED' ? self::DORM_BED_ITEMS : self::ROOM_RENTAL_ITEMS;
            $names = collect($defaults)->concat($contract->room->amenities()->pluck('name'))
                ->map(fn (string $name) => $contract->contract_type === 'ROOM_RENTAL' && Str::lower(trim($name)) === 'nệm' ? 'Ballet, nệm.' : trim($name))
                ->filter()->unique(fn (string $name) => Str::lower($name))->values();
            $contract->handoverReports()->create([
                'type' => 'CHECK_IN', 'status' => 'DRAFT',
                'items' => $names->map(fn (string $name) => ['name' => $name, 'quantity' => 1, 'condition' => null, 'note' => null])->all(),
            ]);
            if ($contract->is_demo) {
                $this->contracts->refreshDemoSnapshot($user, $contract);
            }
        }, 3);
    }

    public function update(User $user, int $contractId, array $items): void
    {
        DB::transaction(function () use ($user, $contractId, $items): void {
            $report = $this->lockedReport($user, $contractId);
            $this->ensureDraft($report);
            $removed = array_diff(array_column($report->items ?? [], 'name'), array_column($items, 'name'));
            if ($removed !== [] || count($items) < count($report->items ?? [])) {
                $contract = $report->contract()->lockForUpdate()->firstOrFail();
                if ($contract->status !== 'DRAFT' || $contract->finalized_at !== null || $contract->signing_snapshot !== null || $contract->document()->exists()) {
                    throw ValidationException::withMessages(['items' => 'Chỉ được xóa hạng mục bàn giao khi đang tạo hợp đồng, trước khi chốt hoặc ký.']);
                }
            }
            $report->update(['items' => array_map(fn (array $item) => [
                'name' => trim($item['name']), 'quantity' => $item['quantity'] ?? 1, 'condition' => $item['condition'] ?? null,
                'note' => $item['note'] ?? null, 'key_count' => $item['key_count'] ?? null, 'remote_count' => $item['remote_count'] ?? null,
            ], $items)]);
            $contract = $report->contract;
            if ($contract->is_demo) {
                $this->contracts->refreshDemoSnapshot($user, $contract);
            }
        });
    }

    public function confirm(User $user, int $contractId): void
    {
        DB::transaction(function () use ($user, $contractId): void {
            $report = $this->lockedReport($user, $contractId);
            $this->ensureDraft($report);
            $report->update(['status' => 'CONFIRMED', 'confirmed_at' => now()]);
            $contract = $report->contract;
            if ($contract->is_demo) {
                $this->contracts->refreshDemoSnapshot($user, $contract);
            }
        });
    }

    private function lockedReport(User $user, int $contractId): ContractHandoverReport
    {
        $contract = $this->contracts->find($user, $contractId);
        $contract = $contract->newQuery()->lockForUpdate()->findOrFail($contractId);

        return $contract->handoverReports()->where('type', 'CHECK_IN')->lockForUpdate()->firstOrFail();
    }

    private function ensureDraft(ContractHandoverReport $report): void
    {
        if ($report->status !== 'DRAFT') {
            throw ValidationException::withMessages(['status' => 'Biên bản đã xác nhận không thể sửa.']);
        }
    }
}
