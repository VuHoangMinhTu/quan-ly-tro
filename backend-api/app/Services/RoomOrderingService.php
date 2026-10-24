<?php

namespace App\Services;

use App\Models\BoardingHouse;
use App\Models\Room;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoomOrderingService
{
    public function ordered(Collection $rooms): Collection
    {
        $rooms->loadMissing('floor');

        return $rooms->sort(function (Room $a, Room $b): int {
            // Keep floor groups together; rooms without a floor follow named floors.
            $floor = ($a->floor_id === null ? 1 : 0) <=> ($b->floor_id === null ? 1 : 0);
            $floorOrder = ($a->floor?->sort_order ?? 0) <=> ($b->floor?->sort_order ?? 0);
            $floorId = ($a->floor_id ?? 0) <=> ($b->floor_id ?? 0);
            $custom = ($a->sort_order === null ? 1 : 0) <=> ($b->sort_order === null ? 1 : 0);

            return $floor ?: $floorOrder ?: $floorId ?: $custom
                ?: (($a->sort_order ?? 0) <=> ($b->sort_order ?? 0))
                ?: strnatcasecmp($a->room_code, $b->room_code)
                ?: strcmp($a->room_code, $b->room_code)
                ?: ($a->id <=> $b->id);
        })->values();
    }

    public function nextFor(BoardingHouse $house, ?int $floorId): int
    {
        // All room creation/reorder paths share the same lock, including a new group.
        $house->newQuery()->whereKey($house->id)->lockForUpdate()->firstOrFail();

        return (int) $house->rooms()->where('floor_id', $floorId)->max('sort_order') + 1;
    }

    public function create(BoardingHouse $house, array $data): Room
    {
        return DB::transaction(function () use ($house, $data): Room {
            $next = $this->nextFor($house, $data['floor_id'] ?? null);

            return $house->rooms()->create([...$data, 'sort_order' => $data['sort_order'] ?? $next]);
        });
    }

    public function reorder(BoardingHouse $house, ?int $floorId, array $ids): void
    {
        DB::transaction(function () use ($house, $floorId, $ids): void {
            $this->nextFor($house, $floorId);
            if ($floorId !== null && ! $house->floors()->whereKey($floorId)->exists()) {
                throw ValidationException::withMessages(['floor_id' => 'Tầng không thuộc nhà trọ này.']);
            }
            $existing = $house->rooms()->where('floor_id', $floorId)->lockForUpdate()->pluck('id');
            if (count($ids) !== $existing->count() || $existing->diff($ids)->isNotEmpty()) {
                throw ValidationException::withMessages(['room_ids' => 'Danh sách phòng đã thay đổi hoặc không thuộc nhóm đã chọn. Vui lòng tải lại danh sách.']);
            }
            foreach ($ids as $position => $id) {
                $house->rooms()->whereKey($id)->update(['sort_order' => $position + 1]);
            }
        });
    }
}
