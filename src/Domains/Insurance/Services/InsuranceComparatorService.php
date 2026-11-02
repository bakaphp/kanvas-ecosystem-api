<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

use Kanvas\Insurance\DataTransferObject\InsuranceSearchEntry;

class InsuranceComparatorService
{
    private const UNPRICED_CENTS = PHP_INT_MAX;

    public function rank(array $entries): array
    {
        usort(
            $entries,
            fn (InsuranceSearchEntry $a, InsuranceSearchEntry $b): int => $this->sortKey($a) <=> $this->sortKey($b)
        );

        return array_values($entries);
    }

    private function sortKey(InsuranceSearchEntry $entry): array
    {
        $price = $entry->price();

        return [
            $entry->sponsored ? 0 : 1,
            -(int) round($entry->commission * 100),
            -$entry->recommendedScore,
            $this->emitRank($entry->canEmit()),
            $price === null ? self::UNPRICED_CENTS : (int) round($price * 100),
            $entry->planId,
        ];
    }

    private function emitRank(?bool $canEmit): int
    {
        return match ($canEmit) {
            true => 0,
            null => 1,
            false => 2,
        };
    }
}
