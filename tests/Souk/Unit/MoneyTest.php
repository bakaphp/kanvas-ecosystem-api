<?php

declare(strict_types=1);

namespace Tests\Souk\Unit;

use Baka\Support\Money;
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

    public function testMinorUnitsCoversEveryStripeZeroAndThreeDecimalCurrency(): void
    {
        $this->assertSame(1000, Money::minorUnits(Money::of('1000'), 'krw'));
        $this->assertSame(500, Money::minorUnits(Money::of('500'), 'XOF'));
        $this->assertSame(10500, Money::minorUnits(Money::of('10.5'), 'KWD'));
        $this->assertSame(1999, Money::minorUnits(Money::of(19.99), 'usd'));
    }

    public function testMinorUnitsDoesNotDriftOnFloatInput(): void
    {
        $this->assertSame(30, Money::minorUnits(Money::of(0.1 + 0.2), 'USD'));
        $this->assertSame(1015, Money::minorUnits(Money::of(10.15), 'USD'));
    }

    public function testFromMinorUnitsIsTheInverse(): void
    {
        $this->assertSame('9.75', Money::fromMinorUnits(975, 'usd'));
        $this->assertSame('0.00', Money::fromMinorUnits(0, 'USD'));
        $this->assertSame('1000', Money::fromMinorUnits(1000, 'JPY'));
        $this->assertSame('10.500', Money::fromMinorUnits(10500, 'KWD'));
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
}
