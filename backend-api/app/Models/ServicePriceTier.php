<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
// là bảng dùng để lưu các bậc giá cho một dịch vụ có cách tính TIERED —  là tính theo bậc thang.

/** Ví dụ: Dịch vụ điện có cách tính TIERED như sau:
 * 0 → 50 kWh       : 2.000đ/kWh
 * 50 → 100 kWh     : 2.500đ/kWh
 * 100 → 200 kWh    : 3.000đ/kWh
 * Trên 200 kWh     : 3.500đ/kWh
 */
class ServicePriceTier extends Model
{
    protected $fillable = [
        'service_id',
        'from_quantity',
        'to_quantity',
        'unit_price',
        'tier_order',
    ];

    protected $casts = [
        'from_quantity' => 'decimal:2',
        'to_quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'tier_order' => 'integer',
    ];

    public function service()
    {
        return $this->belongsTo(Service::class);
    }
}
