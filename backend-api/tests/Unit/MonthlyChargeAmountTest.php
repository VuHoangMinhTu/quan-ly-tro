<?php

namespace Tests\Unit;

use App\Services\MonthlyChargeAmount;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class MonthlyChargeAmountTest extends TestCase
{
    #[TestWith(['2', '10000', null, '20000.00'])]
    #[TestWith(['0.1', '0.2', null, '0.02'])]
    #[TestWith(['2', '10000', '7500', '7500.00'])]
    public function test_exact_amount_or_explicit_agreement(string $quantity, string $price, ?string $confirmed, string $expected): void
    {
        $this->assertSame($expected, MonthlyChargeAmount::calculate($quantity, $price, $confirmed));
    }
}
