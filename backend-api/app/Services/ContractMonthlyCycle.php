<?php

namespace App\Services;

use DateTimeImmutable;

/** Date-only contract-anniversary windows; never infer a month from the invoice label. */
final class ContractMonthlyCycle
{
    public static function isFull(string $contractStart, string $usageStart, string $usageEnd): bool
    {
        $origin = self::date($contractStart);
        $from = self::date($usageStart);
        $to = self::date($usageEnd);
        if (! $origin || ! $from || ! $to || $from < $origin || $to <= $from) {
            return false;
        }
        $months = ((int) $from->format('Y') - (int) $origin->format('Y')) * 12
            + (int) $from->format('m') - (int) $origin->format('m');
        if ($months < 0) {
            return false;
        }

        return self::anniversary($origin, $months) == $from
            && self::anniversary($origin, $months + 1) == $to;
    }

    private static function anniversary(DateTimeImmutable $origin, int $months): DateTimeImmutable
    {
        $target = $origin->modify('first day of this month')->modify('+' . $months . ' months');
        $day = min((int) $origin->format('d'), (int) $target->format('t'));
        return $target->setDate((int) $target->format('Y'), (int) $target->format('m'), $day);
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        $value = substr($value, 0, 10);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }
}
