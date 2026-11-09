<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Support\DateHelper;
use PHPUnit\Framework\TestCase;

class DateHelperStrictParseTest extends TestCase
{
    public function testParseExactReadsRealDatesWithoutZeroPadding(): void
    {
        $this->assertSame('2026-02-01', DateHelper::parseExact('1/2/2026', 'd/m/Y')?->format('Y-m-d'));
        $this->assertSame('2026-06-13', DateHelper::parseExact('13/06/2026', 'd/m/Y')?->format('Y-m-d'));
    }

    public function testParseExactRefusesDatesThatWouldRollOver(): void
    {
        $this->assertNull(DateHelper::parseExact('31/02/2026', 'd/m/Y'));
        $this->assertNull(DateHelper::parseExact('13/02/2026', 'm/d/Y'));
        $this->assertNull(DateHelper::parseExact('not a date', 'd/m/Y'));
    }

    public function testParseCarbonStrictRefusesImpossibleDates(): void
    {
        $this->assertSame('2026-02-28', DateHelper::parseCarbonStrict('2026-02-28')?->toDateString());
        $this->assertSame('2026-06-02', DateHelper::parseCarbonStrict('2026-06-02T10:00:00Z')?->toDateString());
        $this->assertNull(DateHelper::parseCarbonStrict('2026-02-31'));
        $this->assertNull(DateHelper::parseCarbonStrict('garbage'));
    }

    public function testParseExactRefusesNullBytes(): void
    {
        $this->assertNull(DateHelper::parseExact("01/02/2026\0", 'd/m/Y'));
    }

    public function testParseYmdRefusesNullBytes(): void
    {
        $this->assertNull(DateHelper::parseYmd("2026-02-01\0"));
    }

    public function testParseYmdRequiresAnExactCalendarDate(): void
    {
        $this->assertSame('2024-02-29', DateHelper::parseYmd('2024-02-29')?->format('Y-m-d'));
        $this->assertNull(DateHelper::parseYmd('2026-02-29'));
        $this->assertNull(DateHelper::parseYmd('2026-2-1'));
        $this->assertNull(DateHelper::parseYmd('2026-02-01 trailing text'));
        $this->assertNull(DateHelper::parseYmd(''));
    }
}
