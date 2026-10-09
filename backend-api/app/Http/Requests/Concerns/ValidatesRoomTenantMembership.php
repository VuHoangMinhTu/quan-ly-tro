<?php

namespace App\Http\Requests\Concerns;

use App\Models\Room;
use App\Models\RoomTenant;
use Illuminate\Validation\Validator;

trait ValidatesRoomTenantMembership
{
    private function validateRoomTenantMembership(
        Validator $validator,
        ?Room $room,
        ?int $ignoredRoomTenantId = null,
        bool $isPrimary = false
    ): void {
        if ($validator->errors()->any() || ! $room) {
            return;
        }

        $status = $this->input('status');
        $moveOutDate = $this->input('move_out_date');

        if ($status === 'ACTIVE' && $moveOutDate !== null) {
            $validator->errors()->add('move_out_date', 'Người đang ở không được có ngày chuyển đi.');
        }

        if ($status === 'MOVED_OUT' && $moveOutDate === null) {
            $validator->errors()->add('move_out_date', 'Vui lòng nhập ngày chuyển đi khi người thuê đã chuyển đi.');
        }

        if ($status !== 'ACTIVE') {
            return;
        }

        $memberships = RoomTenant::query()
            ->where('room_id', $room->id)
            ->when($ignoredRoomTenantId, fn ($query) => $query->whereKeyNot($ignoredRoomTenantId));

        if ($memberships->clone()->where('tenant_id', $this->integer('tenant_id'))->where('status', 'ACTIVE')->exists()) {
            $validator->errors()->add('tenant_id', 'Người thuê này đã đang ở trong phòng.');
        }

        if ($isPrimary && $memberships->where('is_primary', true)->where('status', 'ACTIVE')->exists()) {
            $validator->errors()->add('is_primary', 'Phòng đã có người đứng tên đang ở.');
        }
    }
}
