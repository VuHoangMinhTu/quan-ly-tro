<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Room;
use App\Models\UtilityMeter;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * V2 handover evidence from the frozen opening readings in the contract.
 * Never infer a value from the previous tenant's readings or overwrite evidence.
 */
class ContractOpeningCheckIn
{
    public function __construct(private BillingV2Evidence $evidence) {}

    /** Call inside a room-locked transaction (activation), or from the repair command. */
    public function sync(Contract $contract, int $actor): array
    {
        if ($contract->status !== 'ACTIVE' || $contract->is_demo || ! $contract->start_date) {
            throw ValidationException::withMessages(['contract_id' => 'Chỉ đồng bộ chỉ số đầu cho hợp đồng ACTIVE thực tế.']);
        }
        $room = Room::query()->lockForUpdate()->findOrFail($contract->room_id);
        // KTX shared meters belong to the room, not to a single bed contract.
        if ($room->room_type === 'DORMITORY') {
            return ['created_or_existing' => 0, 'skipped' => 'DORMITORY'];
        }
        $terms = $contract->agreedServices()->where('is_active', true)
            ->whereIn('service_type', ['ELECTRICITY', 'WATER'])
            ->where('billing_method', 'PER_UNIT')->get()->keyBy('service_id');
        $openings = $contract->utilityOpenings()->get();
        $meterIds = $room->utilityMeters()->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $count = 0;
        foreach ($openings as $opening) {
            if (! $terms->has($opening->service_id)) {
                continue;
            }
            if (! in_array((int) $opening->utility_meter_id, $meterIds, true)) {
                throw ValidationException::withMessages(['utility_openings' => 'Công tơ được chọn trong hợp đồng không còn hoạt động hoặc không thuộc phòng.']);
            }
            $meter = UtilityMeter::query()->with(['service', 'room.boardingHouse'])->findOrFail($opening->utility_meter_id);
            if ((int) $meter->service_id !== (int) $opening->service_id || ! in_array($meter->service?->type, ['ELECTRICITY', 'WATER'], true)) {
                throw ValidationException::withMessages(['utility_openings' => 'Công tơ không khớp dịch vụ trong hợp đồng.']);
            }
            if ($opening->reading_date->format('Y-m-d') !== $contract->start_date->format('Y-m-d')) {
                throw ValidationException::withMessages(['utility_openings' => 'Ngày chỉ số đầu phải trùng ngày bắt đầu hợp đồng trước khi ghi mốc V2.']);
            }
            // Contract wizard records a DATE, not physical time. Persist at day start
            // consistently; never claim an unobserved clock time was verified.
            $at = $opening->reading_date->format('Y-m-d').' 00:00:00';
            $scope = 'CHECK_IN:'.$contract->id;
            $existing = DB::table('utility_checkpoints')->where('utility_meter_id', $meter->id)
                ->where('scope_key', $scope)->orderByDesc('revision')->first();
            if ($existing) {
                if (substr($existing->observed_at, 0, 10) !== $opening->reading_date->format('Y-m-d') || ! BigDecimal::of((string) $existing->reading_value)->isEqualTo((string) $opening->opening_reading)) {
                    throw ValidationException::withMessages(['utility_openings' => 'CHECK_IN hiện có khác chỉ số đầu hợp đồng. Phải đối soát lịch sử, không ghi đè.']);
                }
                $count++;
                continue;
            }
            $this->evidence->checkpoint($meter, [
                'kind' => 'CHECK_IN', 'contract_id' => $contract->id,
                'observed_at' => $at, 'reading_value' => (string) $opening->opening_reading,
            ], $actor);
            $count++;
        }
        // Missing opening should not be silently treated as zero or previous-tenant value.
        foreach ($terms as $term) {
            $hasOpening = $openings->contains(fn ($opening) => (int) $opening->service_id === (int) $term->service_id);
            if (! $hasOpening) {
                throw ValidationException::withMessages(['utility_openings' => 'Thiếu chỉ số đầu của dịch vụ PER_UNIT trong hợp đồng.']);
            }
        }

        return ['created_or_existing' => $count];
    }
}
