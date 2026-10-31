<?php

declare(strict_types=1);

namespace Tests\Souk\Unit;

use Brick\Math\BigDecimal;
use Kanvas\Souk\Support\Money;
use Tests\TestCaseUnit;

final class MoneyTest extends TestCaseUnit
{
    public function testOfZeroStringIsNotNull(): void
    {
        $result = Money::of('0');

        $this->assertNotNull($result);
        $this->assertTrue($result->isZero());
    }

    public function testOfIntegerInput(): void
    {
        $result = Money::of(100);

        $this->assertSame('100', $result->__toString());
    }

    public function testMinorUnitsForTwoDecimalCurrency(): void
    {
        $amount = Money::of('10.00');

        $this->assertSame(1000, Money::minorUnits($amount, 'USD'));
        $this->assertSame(1000, Money::minorUnits($amount, 'DOP'));
        $this->assertSame(1000, Money::minorUnits($amount, 'MXN'));
    }

    public function testMinorUnitsForZeroDecimalCurrency(): void
    {
        $amount = Money::of('10.00');

        $this->assertSame(10, Money::minorUnits($amount, 'JPY'));
    }

    public function testMinorUnitsDefaultsToDOP(): void
    {
        $amount = Money::of('5.50');

        $this->assertSame(550, Money::minorUnits($amount));
    }

    public function testRoundCollectUsesHalfUp(): void
    {
        $result = Money::roundCollect(Money::of('0.005'));

        $this->assertSame('0.01', $result->__toString());
    }

    public function testRoundCollectRoundsDownWhenBelow(): void
    {
        $result = Money::roundCollect(Money::of('0.004'));

        $this->assertSame('0.00', $result->__toString());
    }

    public function testRoundCollectPreservesTwoDecimalScale(): void
    {
        $result = Money::roundCollect(Money::of('12.345'));

        $this->assertSame('12.35', $result->__toString());
    }

    public function testAllocateSumEqualsTotal(): void
    {
        $total = Money::of('10.00');
        $weights = [1, 1, 1];

        $allocations = Money::allocate($total, $weights);

        $sum = BigDecimal::zero();
        foreach ($allocations as $a) {
            $sum = $sum->plus($a);
        }

        $this->assertSame($total->toScale(2)->__toString(), $sum->toScale(2)->__toString());
    }

    public function testAllocateWithUnevenWeightsNoRoundingLeakage(): void
    {
        $total = Money::of('10.00');
        $weights = [1, 2, 3];

        $allocations = Money::allocate($total, $weights);
        $this->assertCount(3, $allocations);

        $sum = BigDecimal::zero();
        foreach ($allocations as $a) {
            $sum = $sum->plus($a);
        }

        $this->assertSame('10.00', $sum->toScale(2)->__toString());
    }

    public function testAllocateWithSubtotalCapNoItemExceedsCap(): void
    {
        $total = Money::of('9.00');
        $weights = [3, 3, 3];
        $cap = Money::of('2.50');

        $allocations = Money::allocate($total, $weights, $cap);

        foreach ($allocations as $a) {
            $this->assertLessThanOrEqual(0, $a->compareTo($cap));
        }
    }

    public function testAllocateWithAllZeroWeightsDistributesEvenly(): void
    {
        $total = Money::of('9.00');
        $weights = [0, 0, 0];

        $allocations = Money::allocate($total, $weights);
        $this->assertCount(3, $allocations);

        $sum = BigDecimal::zero();
        foreach ($allocations as $a) {
            $sum = $sum->plus($a);
        }

        $this->assertSame('9.00', $sum->toScale(2)->__toString());
    }

    public function testAllocateWithEmptyWeightsReturnsEmpty(): void
    {
        $allocations = Money::allocate(Money::of('10.00'), []);

        $this->assertSame([], $allocations);
    }

    public function testAllocateLargestRemainderGoesToLargestFraction(): void
    {
        // weights [1, 2], total 0.10
        // exact shares: 0.0333... and 0.0666...
        // floors: 0.03 + 0.06 = 0.09 → one cent remainder
        // largest fractional remainder belongs to weight-2 slot (0.0666... → remainder 0.0066...)
        // vs weight-1 slot (0.0333... → remainder 0.0033...)
        // so the extra cent must land on index 1
        $allocations = Money::allocate(Money::of('0.10'), [1, 2]);

        $this->assertSame('0.03', $allocations[0]->toScale(2)->__toString());
        $this->assertSame('0.07', $allocations[1]->toScale(2)->__toString());
    }
}
