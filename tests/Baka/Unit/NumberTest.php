<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Support\Money;
use Baka\Support\Number;
use PHPUnit\Framework\TestCase;

class NumberTest extends TestCase
{
    public function testGroupingSeparators(): void
    {
        $this->assertSame(1299.99, Number::toFloat('1,299.99'));
        $this->assertSame(20000.0, Number::toFloat('20.000'));
        $this->assertSame(1500000.0, Number::toFloat('1.500.000'));
        $this->assertSame(19.99, Number::toFloat('19.99'));
        $this->assertSame(60.0, Number::toFloat('60,'));
        $this->assertNull(Number::toFloat('abc'));
    }

    public function testMeasurementWithUnitAndSuffix(): void
    {
        $this->assertSame(['value' => 62000.0, 'unit' => 'mi'], Number::parseMeasurement('62,000 mi'));
        $this->assertSame(['value' => 45000.0, 'unit' => 'km'], Number::parseMeasurement('45k km'));
        $this->assertSame(['value' => 2019.0, 'unit' => null], Number::parseMeasurement('2019'));
        $this->assertNull(Number::parseMeasurement('low miles'));
    }

    public function testRatioIsNullWithNothingToDivideBy(): void
    {
        $this->assertSame(0.5, Number::ratio(1, 2));
        $this->assertSame(0.3333, Number::ratio(1, 3));
        $this->assertSame(0.33, Number::ratio(1, 3, 2));
        $this->assertSame(0.0, Number::ratio(0, 7));
        $this->assertNull(Number::ratio(0, 0));
        $this->assertNull(Number::ratio(3, 0));
    }

    public function testWithoutTrailingZerosKeepsTheZerosOfAWholeNumber(): void
    {
        $this->assertSame('70.5', Number::withoutTrailingZeros(70.5, 8));
        $this->assertSame('0.0141844', Number::withoutTrailingZeros(1 / 70.5, 8));
        $this->assertSame('18', Number::withoutTrailingZeros(18.0));
        $this->assertSame('100', Number::withoutTrailingZeros(100.0));
        $this->assertSame('100', Number::withoutTrailingZeros(100, 0));
        $this->assertSame('0', Number::withoutTrailingZeros(0.0));
    }

    public function testWithoutTrailingZerosTakesStoredDecimalsAndExactSums(): void
    {
        $this->assertSame('10', Number::withoutTrailingZeros('10.000000', 6));
        $this->assertSame('1234567.25', Number::withoutTrailingZeros(1234567.25, 6));
        $this->assertSame('0.3', Number::withoutTrailingZeros(0.1 + 0.2, 6));
        $this->assertSame('1.2346', Number::withoutTrailingZeros('1.23455', 4));
        $this->assertSame('30', Number::withoutTrailingZeros(Money::sum('10.5', '19.5'), 6));
    }
}
