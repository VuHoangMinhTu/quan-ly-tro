<?php

namespace Tests\Unit;

use App\Services\TieredUtilityAllocator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TieredUtilityAllocatorTest extends TestCase
{
    public function test_distributes_one_meter_total_by_actual_usage_including_owner_segments(): void
    {
        $segments = ['abandoned' => '30', 'vacant' => '5', 'tenant_b' => '15'];

        $result = (new TieredUtilityAllocator)->allocate('50', '170000.00', $segments);

        $this->assertSame(['abandoned' => '102000.00', 'vacant' => '17000.00', 'tenant_b' => '51000.00'], $result);
    }

    public function test_distributes_remaining_cent_by_remainder_then_chronological_input_order(): void
    {
        $allocator = new TieredUtilityAllocator;

        $result = $allocator->allocate('3', '1.00', ['first' => '1', 'second' => '1', 'third' => '1']);

        $this->assertSame(['first' => '0.34', 'second' => '0.33', 'third' => '0.33'], $result);
        $this->assertSame($result, $allocator->allocate('3', '1.00', ['first' => '1', 'second' => '1', 'third' => '1']));
    }

    public function test_preserves_zero_consumption_without_division_by_zero(): void
    {
        $result = (new TieredUtilityAllocator)->allocate('0', '0', ['tenant' => '0', 'vacant' => '0']);

        $this->assertSame(['tenant' => '0.00', 'vacant' => '0.00'], $result);
    }

    public function test_rejects_unreconciled_meter_quantity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new TieredUtilityAllocator)->allocate('50', '100', ['tenant' => '45']);
    }

    public function test_rejects_negative_segment_even_if_sum_matches_meter(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new TieredUtilityAllocator)->allocate('50', '100', ['tenant' => '55', 'vacant' => '-5']);
    }

    public function test_rejects_nonzero_tariff_total_for_zero_usage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new TieredUtilityAllocator)->allocate('0', '1', ['tenant' => '0']);
    }
}
