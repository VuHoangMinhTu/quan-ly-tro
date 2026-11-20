<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UtilityMeterPeriodBoundary extends Model
{
    public $timestamps = false;

    protected $fillable = ['utility_meter_id', 'billing_period', 'start_checkpoint_id', 'end_checkpoint_id'];
}
