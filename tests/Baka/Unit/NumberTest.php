<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

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
}
