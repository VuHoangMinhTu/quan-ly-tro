<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class InvoiceContractEligibility
{
    public function reason(Contract $contract, string $period): ?string
    {
        $start = Carbon::parse($period)->startOfMonth()->toDateString();
        $end = Carbon::parse($period)->endOfMonth()->toDateString();
        if ($contract->status === 'TERMINATED') {
            return 'Hợp đồng này đã chấm dứt. Không thể tạo hóa đơn định kỳ mới.';
        }
        if ($contract->status !== 'ACTIVE') {
            return 'Chỉ có thể tạo hóa đơn định kỳ cho hợp đồng đang hoạt động.';
        }
        if ($contract->start_date === null || $contract->monthly_rent === null) {
            return 'Hợp đồng chưa đủ ngày bắt đầu và giá thuê thỏa thuận.';
        }
        if ($contract->end_date && $contract->end_date->toDateString() < $start) {
            return 'Hợp đồng này đã kết thúc trước kỳ hóa đơn được chọn.';
        }
        if ($contract->start_date->toDateString() > $end) {
            return 'Hợp đồng chưa bắt đầu trong kỳ hóa đơn được chọn.';
        }
        $effectiveEnd = min($end, $contract->end_date?->toDateString() ?? $end);
        $primary = $this->primaryOccupants($contract);
        if (($warning = $this->warning($contract)) !== null) {
            return $warning;
        }
        $overlaps = $primary->contains(fn ($occupant) => $occupant->move_in_date !== null
            && $occupant->move_in_date->toDateString() <= $effectiveEnd
            && $occupant->status === 'ACTIVE' && $occupant->move_out_date === null);

        return $overlaps ? null : 'Người đứng tên không có lịch sử cư trú hợp lệ thuộc hợp đồng và phòng trong kỳ hóa đơn được chọn.';
    }

    private function primaryOccupants(Contract $contract): Collection
    {
        $contract->loadMissing(['occupants', 'room', 'bed']);

        return $contract->occupants->filter(fn ($occupant) => (int) $occupant->room_id === (int) $contract->room_id
            && (int) $occupant->tenant_id === (int) $contract->tenant_id && $occupant->is_primary
            && ($contract->room->room_type !== 'DORMITORY' || ((int) $occupant->bed_id === (int) $contract->bed_id && $contract->bed_id !== null)));
    }

    public function warning(Contract $contract): ?string
    {
        $contract->loadMissing(['room', 'bed']);
        if ($contract->status === 'ACTIVE' && $contract->room->room_type === 'PRIVATE_ROOM' && ($contract->contract_type !== 'ROOM_RENTAL' || $contract->bed_id !== null)) {
            return 'Loại hợp đồng hoặc giường không khớp phòng riêng. Vui lòng kiểm tra dữ liệu hợp đồng.';
        }
        if ($contract->status === 'ACTIVE' && $contract->room->room_type === 'DORMITORY'
            && ($contract->contract_type !== 'DORM_BED' || $contract->bed === null || (int) $contract->bed->room_id !== (int) $contract->room_id || $contract->bed->status !== 'OCCUPIED')) {
            return 'Giường không khớp hợp đồng hoặc chưa có người ở hợp lệ. Vui lòng kiểm tra dữ liệu giường.';
        }
        if ($contract->status === 'ACTIVE' && $contract->room->room_type === 'DORMITORY') {
            $contract->loadMissing('bed.roomTenants');
            $primaries = $contract->bed->roomTenants->filter(fn ($occupant) => (int) $occupant->room_id === (int) $contract->room_id
                && $occupant->is_primary && $occupant->status === 'ACTIVE' && $occupant->move_out_date === null
                && $occupant->move_in_date !== null && $occupant->move_in_date->toDateString() <= now()->toDateString());
            if ($primaries->count() !== 1 || (int) $primaries->first()->contract_id !== (int) $contract->id) {
                return 'Người ở và hợp đồng của giường chưa đồng bộ. Vui lòng kiểm tra dữ liệu; hệ thống không tự chọn người thuê.';
            }
        }

        return $contract->status === 'ACTIVE' && ! $this->primaryOccupants($contract)->contains(fn ($occupant) => $occupant->status === 'ACTIVE' && $occupant->move_out_date === null && $occupant->move_in_date !== null && $occupant->move_in_date->toDateString() <= now()->toDateString())
            ? 'Hợp đồng đang ACTIVE nhưng người đứng tên không còn cư trú ACTIVE hợp lệ. Vui lòng kiểm tra lịch sử cư trú.' : null;
    }

    public function assertEligible(Room $room, int $contractId, string $period): Contract
    {
        if (($warning = $this->roomWarning($room)) !== null) {
            throw ValidationException::withMessages(['contract_id' => $warning]);
        }
        $owner = $room->boardingHouse->landlord_id;
        $contract = $room->contracts()->where('landlord_id', $owner)
            ->whereHas('tenant', fn ($query) => $query->where('landlord_id', $owner))
            ->with(['occupants', 'bed'])->find($contractId);
        if ($contract === null) {
            throw ValidationException::withMessages(['contract_id' => 'Hợp đồng phải thuộc đúng phòng và chủ trọ được phép truy cập.']);
        }
        if (($reason = $this->reason($contract, $period)) !== null) {
            throw ValidationException::withMessages(['contract_id' => $reason]);
        }

        return $contract;
    }

    public function roomWarning(Room $room): ?string
    {
        if ($room->room_type !== 'PRIVATE_ROOM') {
            return null;
        }
        $primaries = $room->roomTenants()->where('is_primary', true)->where('status', 'ACTIVE')->whereNull('move_out_date')
            ->whereDate('move_in_date', '<=', now()->toDateString())->get(['contract_id']);

        return $primaries->count() > 1 || $primaries->contains(fn ($occupant) => $occupant->contract_id === null)
            ? 'Dữ liệu người đứng tên hiện tại của phòng chưa đồng bộ. Vui lòng kiểm tra hợp đồng và lịch sử cư trú; hệ thống không tự chọn hợp đồng.' : null;
    }
}
