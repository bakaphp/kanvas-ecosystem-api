<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\DataTransferObject;

use Baka\Support\DateHelper;
use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

/**
 * Maps one Acumatica financial period (dbo.FinPeriod) to the (start, end) bounds
 * OpenFiscalPeriodAction expects.
 *
 * FinPeriodID is YYYYPP where PP is a *fiscal* period number, NOT a calendar month — for a
 * fiscal year starting in July, PP=01 is July. So the calendar bounds must come from
 * StartDate/EndDate, never derived from the id. Acumatica stores EndDate exclusively (the first
 * day of the next period); Kanvas requires gapless, non-overlapping inclusive bounds, so we pull
 * the exclusive end back by one day.
 */
class AcumaticaImportFiscalPeriod extends Data
{
    public function __construct(
        public readonly string $periodId,
        public readonly ?Carbon $start,
        public readonly ?Carbon $end,
    ) {
    }

    /**
     * @param array<array-key, mixed> $row raw dbo.FinPeriod row (PascalCase columns)
     */
    public static function fromArray(array $row): self
    {
        $start = DateHelper::tryParseCarbon($row['StartDate'] ?? null);
        $end = DateHelper::tryParseCarbon($row['EndDate'] ?? null);

        // Exclusive end (first-of-month) → inclusive last day of the period.
        if ($end !== null && $end->day === 1) {
            $end = $end->copy()->subDay();
        }

        // No usable EndDate but we have a start: assume a single calendar month.
        if ($start !== null && $end === null) {
            $end = $start->copy()->endOfMonth();
        }

        return new self(
            periodId: trim((string) ($row['FinPeriodID'] ?? '')),
            start: $start?->startOfDay(),
            end: $end?->startOfDay(),
        );
    }
}
