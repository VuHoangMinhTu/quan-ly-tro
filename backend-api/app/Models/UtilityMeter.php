<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Brick\Math\BigDecimal;

// Công tơ điện
class UtilityMeter extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'room_id',
        'service_id',
        'meter_code',
        'initial_reading',
        'is_active',
    ];

    protected $casts = ['initial_reading' => 'decimal:2', 'is_active' => 'boolean'];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function readings()
    {
        return $this->hasMany(UtilityReading::class)->orderBy('reading_date');
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function latestReading()
    {
        return $this->hasOne(UtilityReading::class)->latestOfMany('reading_date');
    }

    public function latestFinalizedContractOpening()
    {
        return $this->hasOne(ContractUtilityOpening::class, 'utility_meter_id')
            ->whereHas('contract', fn ($query) => $query->whereIn('status', ['ACTIVE', 'TERMINATED', 'EXPIRED']))
            ->latestOfMany('reading_date');
    }

    private function legacyReadingSnapshot(): array
    {
        $reading = $this->latestReading;
        $opening = $this->latestFinalizedContractOpening;

        if ($opening && (! $reading || $opening->reading_date->greaterThanOrEqualTo($reading->reading_date))) {
            return [
                'reading_value' => $opening->opening_reading,
                'reading_date' => $opening->reading_date->format('Y-m-d'),
                'source' => 'CONTRACT_HANDOVER',
                'contract_id' => $opening->contract_id,
            ];
        }

        if ($reading) {
            return [
                'reading_value' => $reading->reading_value,
                'reading_date' => $reading->reading_date->format('Y-m-d'),
                'source' => 'UTILITY_READING',
                'contract_id' => null,
            ];
        }

        return ['reading_value' => $this->initial_reading, 'reading_date' => null, 'source' => 'METER_INITIAL', 'contract_id' => null];
    }
    /** Display-only snapshot. V2 evidence and legacy readings remain in their own immutable sources. */
    public function currentReadingSnapshot(): array
    {
        $legacy = $this->legacyReadingSnapshot();

        // Corrections append revisions. Keep only the effective revision of each V2 scope.
        // Do not accidentally display a superseded mốc as the current value.
        $effective = DB::table('utility_checkpoints')
            ->where('utility_meter_id', $this->id)
            ->orderByDesc('revision')->orderByDesc('id')
            ->get(['id', 'scope_key', 'revision', 'kind', 'observed_at', 'reading_value'])
            ->unique('scope_key')
            ->sortByDesc('observed_at')
            ->values();
        $latest = $effective->first();

        if ($latest === null) {
            return $legacy;
        }

        $date = substr($latest->observed_at, 0, 10);
        // Date-only V1 entries have no reliable time-of-day. Prefer confirmed V2 on ties.
        if ($legacy['reading_date'] !== null && $date < $legacy['reading_date']) {
            return $legacy;
        }

        // Display the most recent *two confirmed period boundaries* as a reference
        // usage amount. This is not an invoice calculation or an allocation.
        // CHECK_IN/CHECK_OUT points are deliberately not treated as monthly boundaries.
        $boundaries = $effective->where('kind', 'PERIOD_BOUNDARY')->take(2)->values();
        $recentUsage = null;
        if ($latest->kind === 'PERIOD_BOUNDARY' && $boundaries->count() === 2) {
            $difference = BigDecimal::of((string) $boundaries[0]->reading_value)->minus((string) $boundaries[1]->reading_value);
            if (! $difference->isNegative() && $boundaries[0]->observed_at > $boundaries[1]->observed_at) {
                $recentUsage = (string) $difference->strippedOfTrailingZeros();
            }
        }

        return [
            'reading_value' => $latest->reading_value,
            'reading_date' => $date,
            'observed_at' => $latest->observed_at,
            'source' => 'BILLING_V2_CHECKPOINT',
            'checkpoint_kind' => $latest->kind,
            'recent_boundary_usage' => $recentUsage,
            'contract_id' => null,
        ];
    }

}
