<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class MonthlyChargeAmount
{
    public static function calculate(int|string $quantity, int|string $price, int|string|null $confirmed = null): string
    {
        $amount = $confirmed === null ? BigDecimal::of($quantity)->multipliedBy($price) : BigDecimal::of($confirmed);
        if ($amount->isNegative() || $amount->isGreaterThan('9999999999999')) {
            throw ValidationException::withMessages(['amount' => 'Khoản thu vượt giới hạn hoặc âm.']);
        }

        return (string) $amount->toScale(2, RoundingMode::HalfUp);
    }
}
