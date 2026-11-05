<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

class TieredUtilityAllocator
{
    /**
     * Allocate the once-calculated meter tariff total, never supplier cost.
     * Segments must be provided in confirmed chronological order.
     *
     * @param  array<string, string>  $segments
     * @return array<string, string>
     */
    public function allocate(string $quantity, string $tariffTotal, array $segments, int $scale = 2): array
    {
        $total = BigDecimal::of($quantity);
        if (! in_array($scale, [0, 2], true)) {
            throw new InvalidArgumentException('Độ chính xác tiền tệ không hợp lệ.');
        }
        $factor = $scale === 0 ? 1 : 100;
        $money = BigDecimal::of($tariffTotal)->toScale($scale, RoundingMode::Unnecessary);
        $sum = BigDecimal::zero();
        foreach ($segments as $value) {
            $part = BigDecimal::of($value);
            if ($part->isNegative()) {
                throw new InvalidArgumentException('Sản lượng phân bổ không được âm.');
            }
            $sum = $sum->plus($part);
        }
        if ($total->isNegative() || $money->isNegative() || ! $sum->isEqualTo($total)) {
            throw new InvalidArgumentException('Sản lượng phân bổ chưa khớp công tơ hoặc tổng tiền không hợp lệ.');
        }
        if ($total->isZero()) {
            if (! $money->isZero()) {
                throw new InvalidArgumentException('Không thể phân bổ tiền cho công tơ không có tiêu thụ.');
            }

            return array_fill_keys(array_keys($segments), $scale === 0 ? '0' : '0.00');
        }
        $cents = $money->multipliedBy($factor);
        $allocated = BigDecimal::zero();
        $rows = [];
        foreach ($segments as $key => $value) {
            $numerator = $cents->multipliedBy($value);
            $floor = $numerator->dividedBy($total, 0, RoundingMode::Down);
            $rows[] = ['key' => $key, 'cents' => $floor, 'remainder' => $numerator->minus($floor->multipliedBy($total)), 'order' => count($rows)];
            $allocated = $allocated->plus($floor);
        }
        usort($rows, fn (array $a, array $b) => $b['remainder']->compareTo($a['remainder']) ?: $a['order'] <=> $b['order']);
        $remaining = $cents->minus($allocated)->toInt();
        foreach ($rows as $index => &$row) {
            if ($index < $remaining) {
                $row['cents'] = $row['cents']->plus(1);
            }
        }
        unset($row);
        usort($rows, fn (array $a, array $b) => $a['order'] <=> $b['order']);
        $result = [];
        foreach ($rows as $row) {
            $result[$row['key']] = (string) $row['cents']->dividedBy($factor, $scale);
        }

        return $result;
    }
}
