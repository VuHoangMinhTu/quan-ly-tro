<?php

namespace Tests\Unit;

use App\Services\ContractMonthlyCycle;
use PHPUnit\Framework\TestCase;

class ContractMonthlyCycleTest extends TestCase
{
    public function test_full_contract_month_starting_on_the_fourth(): void
    {
        $this->assertTrue(ContractMonthlyCycle::isFull('2026-11-04', '2026-11-04', '2026-12-04'));
        $this->assertTrue(ContractMonthlyCycle::isFull('2026-11-04', '2026-12-04', '2027-01-04'));
    }

    public function test_calendar_month_does_not_replace_contract_anniversary(): void
    {
        $this->assertFalse(ContractMonthlyCycle::isFull('2026-11-04', '2026-11-01', '2026-12-01'));
        $this->assertFalse(ContractMonthlyCycle::isFull('2026-11-04', '2026-11-04', '2026-12-01'));
        $this->assertFalse(ContractMonthlyCycle::isFull('2026-11-04', '2026-11-05', '2026-12-05'));
    }

    public function test_month_end_clamps_but_does_not_drift(): void
    {
        $this->assertTrue(ContractMonthlyCycle::isFull('2027-01-31', '2027-01-31', '2027-02-28'));
        $this->assertTrue(ContractMonthlyCycle::isFull('2027-01-31', '2027-02-28', '2027-03-31'));
    }

    public function test_rejects_bad_or_backwards_windows(): void
    {
        $this->assertFalse(ContractMonthlyCycle::isFull('2026-11-04', '2026-11-04', '2026-11-04'));
        $this->assertFalse(ContractMonthlyCycle::isFull('2026-11-04', '2026-10-04', '2026-11-04'));
    }
}
