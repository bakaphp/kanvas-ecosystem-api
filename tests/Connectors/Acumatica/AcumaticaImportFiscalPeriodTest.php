<?php

declare(strict_types=1);

namespace Tests\Connectors\Acumatica;

use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaImportFiscalPeriod;
use Tests\TestCase;

class AcumaticaImportFiscalPeriodTest extends TestCase
{
    public function testNormalizesExclusiveEndToInclusiveLastDay(): void
    {
        $period = AcumaticaImportFiscalPeriod::fromRow([
            'FinPeriodID' => '202603',
            'StartDate' => '2026-03-01 00:00:00',
            'EndDate' => '2026-04-01 00:00:00',
        ]);

        $this->assertSame('202603', $period->periodId);
        $this->assertSame('2026-03-01', $period->start?->toDateString());
        $this->assertSame('2026-03-31', $period->end?->toDateString());
    }

    public function testFallsBackToCalendarMonthWhenNoEndDate(): void
    {
        $period = AcumaticaImportFiscalPeriod::fromRow([
            'FinPeriodID' => '202602',
            'StartDate' => '2026-02-01 00:00:00',
            'EndDate' => null,
        ]);

        $this->assertSame('2026-02-01', $period->start?->toDateString());
        $this->assertSame('2026-02-28', $period->end?->toDateString());
    }

    public function testKeepsInclusiveMidMonthEndAsIs(): void
    {
        $period = AcumaticaImportFiscalPeriod::fromRow([
            'FinPeriodID' => '202612',
            'StartDate' => '2026-12-01 00:00:00',
            'EndDate' => '2026-12-31 00:00:00',
        ]);

        $this->assertSame('2026-12-31', $period->end?->toDateString());
    }

    public function testNullWhenNoStartDate(): void
    {
        $period = AcumaticaImportFiscalPeriod::fromRow([
            'FinPeriodID' => '202601',
            'StartDate' => null,
            'EndDate' => null,
        ]);

        $this->assertNull($period->start);
        $this->assertNull($period->end);
    }
}
