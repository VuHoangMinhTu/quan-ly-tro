<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Explicit, auditable internal recording of a tenant-agreed fixed-term renewal. */
final class ContractRenewalService
{
    public function __construct(
        private ContractService $contracts,
        private BoardingHouseAccessService $access,
    ) {}

    public function renew(User $actor, int $id, array $data): array
    {
        return DB::transaction(function () use ($actor, $id, $data): array {
            // The room/house locks match the normal contract service lock order.
            $accessible = $this->contracts->find($actor, $id);
            $house = $this->access->house($actor, $accessible->room->boarding_house_id, true);
            abort_unless($this->access->isOwner($actor, $house), 403);
            $room = Room::query()->lockForUpdate()->findOrFail($accessible->room_id);
            $contract = Contract::query()->where('room_id', $room->id)->lockForUpdate()->findOrFail($id);

            $this->check(!$contract->is_demo, 'status', 'Không thể gia hạn hợp đồng DEMO.');
            $this->check(in_array($contract->status, ['ACTIVE', 'EXPIRED'], true), 'status',
                'Chỉ gia hạn hợp đồng đang hiệu lực hoặc hết hạn; hợp đồng đã chấm dứt không được gia hạn.');
            $this->check($room->status !== 'INACTIVE', 'room_id', 'Phòng đã ngừng sử dụng; không thể gia hạn.');
            $this->check($contract->end_date !== null, 'end_date', 'Hợp đồng không có thời hạn kết thúc để gia hạn.');
            $this->check(!DB::table('contract_terminations')->where('contract_id', $id)->exists()
                && !DB::table('contract_settlements')->where('contract_id', $id)->exists(), 'status',
                'Hợp đồng đã bắt đầu trả phòng/quyết toán. Hãy kiểm tra hồ sơ, không thể gia hạn trực tiếp.');

            $reason = trim($data['reason']);
            $this->check(mb_strlen($reason) >= 10, 'reason',
                'Vui lòng ghi rõ lý do gia hạn (ít nhất 10 ký tự).');
            $old = $contract->end_date->format('Y-m-d');
            $new = $data['new_end_date'];
            $this->check($data['expected_end_date'] === $old, 'expected_end_date',
                'Thời hạn hợp đồng đã thay đổi. Vui lòng tải lại trang để kiểm tra trước khi gia hạn.');
            $this->check($new > $old, 'new_end_date',
                'Ngày kết thúc mới phải sau ngày kết thúc hiện tại.');

            // The extension window [old, new) must not overlap another lease.
            // Different beds in one dormitory room remain independent.
            $conflict = $room->contracts()->whereKeyNot($contract->id)
                ->whereIn('status', ['ACTIVE', 'EXPIRED'])
                ->when($contract->contract_type === 'DORM_BED', fn ($query) => $query->where(function ($scope) use ($contract) {
                    $scope->where('contract_type', 'ROOM_RENTAL')->orWhere('bed_id', $contract->bed_id);
                }))
                ->whereDate('start_date', '<', $new)
                ->where(function ($query) use ($old) {
                    $query->whereNull('end_date')->orWhereDate('end_date', '>', $old);
                })->exists();
            $this->check(!$conflict, 'new_end_date',
                'Thời gian gia hạn trùng hợp đồng khác tại phòng/giường này. Vui lòng đối chiếu trước khi tiếp tục.');

            $statusBefore = $contract->status;
            $contract->end_date = $new;
            // An administratively EXPIRED contract can resume only if there is
            // no actual termination/settlement (checked above).
            if ($contract->status === 'EXPIRED') {
                $contract->status = 'ACTIVE';
            }
            $contract->save();
            DB::table('contract_renewals')->insert([
                'contract_id' => $contract->id,
                'old_end_date' => $old,
                'new_end_date' => $new,
                'status_before' => $statusBefore,
                'reason' => $reason,
                'extended_by' => $actor->id,
                'agreement_confirmed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Preserve signed snapshots, issued invoices, deposits and payments.
            // Any legal addendum belongs in the separate signing/document flow.
            return [
                'id' => $contract->id,
                'old_end_date' => $old,
                'end_date' => $new,
                'status' => $contract->status,
            ];
        }, 3);
    }

    private function check(bool $passed, string $field, string $message): void
    {
        if (! $passed) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
