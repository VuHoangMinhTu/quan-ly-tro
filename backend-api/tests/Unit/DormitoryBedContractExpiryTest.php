<?php

namespace Tests\Unit;

use App\Models\Contract;
use App\Services\DormitoryBedBilling;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class DormitoryBedContractExpiryTest extends TestCase
{
    private function contract(string $endDate): Contract
    {
        $contract = new Contract();
        $contract->forceFill([
            'contract_code' => 'KTX-EXPIRY-TEST',
            'start_date' => '2026-11-02',
            'end_date' => $endDate,
        ]);
        return $contract;
    }

    public function test_previous_cycle_ending_at_contract_end_is_valid(): void
    {
        [$start, $end] = app(DormitoryBedBilling::class)->cycle($this->contract('2026-12-02'), '2026-11-01');
        $this->assertSame('2026-11-02', $start->toDateString());
        $this->assertSame('2026-12-02', $end->toDateString());
    }

    public function test_next_cycle_at_contract_end_requires_renewal(): void
    {
        try {
            app(DormitoryBedBilling::class)->cycle($this->contract('2026-12-02'), '2026-12-01');
            $this->fail('Expected contract_expired validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('contract_expired', $e->errors());
            $this->assertStringContainsString('02/01/2027', $e->errors()['contract_expired'][0]);
        }
    }

    public function test_renewed_contract_covers_entire_next_cycle(): void
    {
        [$start, $end] = app(DormitoryBedBilling::class)->cycle($this->contract('2027-01-02'), '2026-12-01');
        $this->assertSame('2026-12-02', $start->toDateString());
        $this->assertSame('2027-01-02', $end->toDateString());
    }

    public function test_mid_cycle_contract_end_remains_eligible_for_partial_review(): void
    {
        [$start, $end] = app(DormitoryBedBilling::class)->cycle($this->contract('2026-12-20'), '2026-12-01');
        $this->assertSame('2026-12-02', $start->toDateString());
        $this->assertSame('2027-01-02', $end->toDateString());
    }
}