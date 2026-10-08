<?php

namespace App\Models;

/**
 * - RoomTenant quản lý những người đang/đã ở trong phòng.
 * *- Không đồng nghĩa với Contract.
 *- Contract chỉ có 1 tenant đứng tên.
 *- RoomTenant có thể chứa:
 *  - người đứng tên hợp đồng
 *  - người ở cùng
 */

use Illuminate\Database\Eloquent\Model;

class RoomTenant extends Model
{
    protected $fillable = [
        'room_id',
        'tenant_id',
        'is_primary',
        'move_in_date',
        'move_out_date',
        'status',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'move_in_date' => 'date:Y-m-d',
        'move_out_date' => 'date:Y-m-d',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
