<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Services;

use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDirectionEnum;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocument;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalNumberRange;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalRangeAnnulmentLine;

/**
 * Unused = range − numbers on a fiscal row − numbers the authority annulled. Returned as closed intervals,
 * because an authorized range runs to millions of numbers and an ANECF request is itself made of intervals.
 */
class FiscalNumberRangeService
{
    /**
     * @return list<array{0: int, 1: int}>
     */
    public function unusedIntervals(FiscalNumberRange $range): array
    {
        $excluded = [
            ...array_map(fn (int $number): array => [$number, $number], $this->usedNumbers($range)),
            ...$this->annulledIntervals($range),
        ];

        return $this->complement($range->range_from, $range->range_to, $excluded);
    }

    /**
     * Every range ever authorized for the type, superseded ones included: a number left behind in an old
     * range still has to be used or annulled.
     *
     * @return array<int, list<array{0: int, 1: int}>> keyed by fiscal_number_range_id
     */
    public function unusedForType(
        int $companiesId,
        string $environment,
        string $country,
        string $documentType
    ): array {
        $ranges = FiscalNumberRange::query()
            ->where('companies_id', $companiesId)
            ->where('environment', $environment)
            ->where('country', $country)
            ->where('document_type', $documentType)
            ->orderBy('id')
            ->get();

        $unused = [];

        foreach ($ranges as $range) {
            $unused[$range->getKey()] = $this->unusedIntervals($range);
        }

        return $unused;
    }

    /**
     * @return list<int>
     */
    private function usedNumbers(FiscalNumberRange $range): array
    {
        $series = (string) $range->series;

        return FiscalDocument::query()
            ->where('companies_id', $range->companies_id)
            ->where('environment', $range->environment)
            ->where('country', $range->country)
            ->where('direction', FiscalDirectionEnum::ISSUED->value)
            ->where('document_type', $range->document_type)
            ->pluck('number')
            ->map(fn (string $number): ?int => str_starts_with($number, $series) && ctype_digit(substr($number, strlen($series)))
                ? (int) substr($number, strlen($series))
                : null)
            ->filter(fn (?int $number): bool => $number !== null && $number >= $range->range_from && $number <= $range->range_to)
            ->values()
            ->all();
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private function annulledIntervals(FiscalNumberRange $range): array
    {
        return FiscalRangeAnnulmentLine::query()
            ->join(
                'fiscal_range_annulments',
                'fiscal_range_annulments.id',
                '=',
                'fiscal_range_annulment_lines.fiscal_range_annulment_id'
            )
            ->where('fiscal_range_annulments.companies_id', $range->companies_id)
            ->where('fiscal_range_annulments.environment', $range->environment)
            ->where('fiscal_range_annulments.country', $range->country)
            ->where('fiscal_range_annulment_lines.document_type', $range->document_type)
            ->where('fiscal_range_annulment_lines.result', FiscalRangeAnnulmentLine::ANNULLED)
            ->get(['fiscal_range_annulment_lines.range_from', 'fiscal_range_annulment_lines.range_to'])
            ->map(fn (FiscalRangeAnnulmentLine $line): array => [$line->range_from, $line->range_to])
            ->all();
    }

    /**
     * @param list<array{0: int, 1: int}> $excluded
     * @return list<array{0: int, 1: int}>
     */
    private function complement(int $from, int $to, array $excluded): array
    {
        usort($excluded, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $free = [];
        $cursor = $from;

        foreach ($excluded as [$start, $end]) {
            if ($end < $cursor) {
                continue;
            }

            if ($start > $to) {
                break;
            }

            if ($start > $cursor) {
                $free[] = [$cursor, $start - 1];
            }

            $cursor = max($cursor, $end + 1);
        }

        if ($cursor <= $to) {
            $free[] = [$cursor, $to];
        }

        return $free;
    }
}
