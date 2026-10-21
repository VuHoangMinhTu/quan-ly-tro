<?php

namespace App\Services;

use App\Models\ContractHandoverReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContractHandoverService
{
    private const DEFAULT_ITEMS = ['Giường', 'Nệm', 'Tủ', 'Khóa/chìa khóa', 'Đèn/ổ cắm', 'Máy lạnh'];

    private const DORM_BED_ITEMS = [
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
            $defaults = $contract->contract_type === 'DORM_BED' ? self::DORM_BED_ITEMS : self::DEFAULT_ITEMS;
            $names = collect($defaults)->concat($contract->room->amenities()->pluck('name'))
                ->map(fn (string $name) => trim($name))->filter()->unique(fn (string $name) => Str::lower($name))->values();
            $contract->handoverReports()->create([
                'type' => 'CHECK_IN', 'status' => 'DRAFT',
                'items' => $names->map(fn (string $name) => ['name' => $name, 'quantity' => 1, 'condition' => 'GOOD', 'note' => null])->all(),
            ]);
        }, 3);
    }

    public function update(User $user, int $contractId, array $items): void
    {
        DB::transaction(function () use ($user, $contractId, $items): void {
            $report = $this->lockedReport($user, $contractId);
            $this->ensureDraft($report);
            $report->update(['items' => array_map(fn (array $item) => [
                'name' => trim($item['name']), 'quantity' => $item['quantity'], 'condition' => $item['condition'], 'note' => $item['note'] ?? null,
            ], $items)]);
        });
    }

    public function confirm(User $user, int $contractId): void
    {
        DB::transaction(function () use ($user, $contractId): void {
            $report = $this->lockedReport($user, $contractId);
            $this->ensureDraft($report);
            $report->update(['status' => 'CONFIRMED', 'confirmed_at' => now()]);
        });
    }

    private function lockedReport(User $user, int $contractId): ContractHandoverReport
    {
        return $this->contracts->find($user, $contractId)->handoverReports()->where('type', 'CHECK_IN')->lockForUpdate()->firstOrFail();
    }

    private function ensureDraft(ContractHandoverReport $report): void
    {
        if ($report->status !== 'DRAFT') {
            throw ValidationException::withMessages(['status' => 'Biên bản đã xác nhận không thể sửa.']);
        }
    }
}
