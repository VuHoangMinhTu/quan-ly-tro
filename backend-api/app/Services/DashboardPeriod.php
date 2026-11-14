<?php

namespace App\Services;

use Carbon\CarbonImmutable as Date;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class DashboardPeriod
{
    public const ZONE = 'Asia/Ho_Chi_Minh';

    public const GROUPS = ['day', 'week', 'month', 'quarter', 'year'];

    public static function scope(array $query, array $rules = []): array
    {
        $rules['boarding_house_id'] = ['sometimes', 'required', 'integer', 'min:1'];
        foreach (array_diff_key($query, $rules) as $key => $value) {
            throw ValidationException::withMessages([$key => 'Tham số không được hỗ trợ.']);
        }

        return Validator::make($query, $rules)->validate();
    }

    public static function resolve(array $query, Date $today): array
    {
        $input = self::scope($query, [
            'preset' => ['sometimes', 'required', 'in:today,yesterday,last_7_days,last_30_days,day,week,month,quarter,year,custom'],
            'anchor_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'from' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'group_by' => ['sometimes', 'required', 'in:day,week,month,quarter,year'],
            'compare' => ['sometimes', 'required', 'in:0,1'],
        ]);
        $preset = $input['preset'] ?? 'month';
        $calendar = in_array($preset, self::GROUPS, true);
        if (isset($input['anchor_date']) && ! $calendar) {
            throw ValidationException::withMessages(['anchor_date' => 'Chỉ dùng cho kỳ lịch.']);
        }
        if ($preset === 'custom' ? ! isset($input['from'], $input['to']) : isset($input['from']) || isset($input['to'])) {
            throw ValidationException::withMessages(['from' => 'Kỳ tùy chọn yêu cầu cả from và to.']);
        }
        $date = fn ($value) => Date::createFromFormat('!Y-m-d', $value, self::ZONE);
        $anchor = isset($input['anchor_date']) ? $date($input['anchor_date']) : $today;
        $start = match ($preset) {
            'custom' => $date($input['from']),
            'yesterday' => $today->subDay(),
            'last_7_days' => $today->subDays(6),
            'last_30_days' => $today->subDays(29),
            default => $calendar ? self::floor($anchor, $preset) : $today,
        };
        $end = match ($preset) {
            'custom' => $date($input['to'])->addDay(),
            'yesterday' => $today,
            default => $calendar ? self::advance($start, $preset) : $today->addDay(),
        };
        $days = (int) $start->diffInDays($end, false);
        if ($days < 1 || $days > 366 || $start->year < 1001 || $end->year > 9999) {
            throw ValidationException::withMessages(['to' => 'Khoảng ngày phải hợp lệ và dài tối đa 366 ngày.']);
        }
        $group = $input['group_by'] ?? (in_array($preset, ['quarter', 'year'], true) || ($preset === 'custom' && $days > 62) ? 'month' : 'day');
        $previous = $calendar ? self::floor($start->subDay(), $preset) : $start->subDays($days);

        return [$input, $start, $end, $group, $previous];
    }

    private static function floor(Date $date, string $group): Date
    {
        return match ($group) {
            'week' => $date->startOfWeek(Date::MONDAY),
            'month' => $date->startOfMonth(),
            'quarter' => $date->startOfQuarter(),
            'year' => $date->startOfYear(),
            default => $date->startOfDay(),
        };
    }

    private static function advance(Date $date, string $group): Date
    {
        return match ($group) {
            'week' => $date->addWeek(),
            'month' => $date->addMonth(),
            'quarter' => $date->addQuarter(),
            'year' => $date->addYear(),
            default => $date->addDay(),
        };
    }

    public static function buckets(Date $start, Date $end, string $group): array
    {
        $buckets = [];
        while ($start < $end) {
            $next = self::advance(self::floor($start, $group), $group)->min($end);
            $buckets[] = [$start, $next];
            $start = $next;
        }

        return $buckets;
    }
}
