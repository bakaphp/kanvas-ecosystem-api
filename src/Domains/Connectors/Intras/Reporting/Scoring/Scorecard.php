<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Reporting\Scoring;

/**
 * An INTRAS scorecard: weighted criteria in, a percentage and a letter out.
 *
 * The arithmetic is the same on all four sheets — "La puntuación obtenida es el resultado de
 * multiplicar el valor del rango en el cual quedó el ejecutivo por el peso general", then "la
 * suma de las puntuaciones obtenidas en cada criterio dividido entre el total máximo de puntos a
 * obtener".
 *
 * The denominator is the **scorable** maximum, not the nominal one. Some criteria cannot be
 * computed — SIPGO holds an investment figure for 3% of registrations, and no PIB share per
 * sector at all — and scoring those as zero would quietly mark every company down. Excluding
 * them from both halves of the fraction instead keeps the percentage honest, and
 * `ScoreResult::coverage` reports how much of the card actually contributed.
 */
final class Scorecard
{
    /**
     * Result bands, identical on all four sheets: 80-100 A, 55-79 B, 31-54 C, 11-30 D, 0-10 E.
     *
     * @var list<array{min: float, letter: string}>
     */
    public const array DEFAULT_LETTERS = [
        ['min' => 80.0, 'letter' => 'A'],
        ['min' => 55.0, 'letter' => 'B'],
        ['min' => 31.0, 'letter' => 'C'],
        ['min' => 11.0, 'letter' => 'D'],
        ['min' => 0.0, 'letter' => 'E'],
    ];

    /**
     * @param list<Criterion>                        $criteria
     * @param list<array{min: float, letter: string}> $letters
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly array $criteria,
        public readonly array $letters = self::DEFAULT_LETTERS,
    ) {
    }

    /**
     * @param array<string, float|string|null> $measurements keyed by criterion key
     */
    public function score(array $measurements): ScoreResult
    {
        $earned = 0.0;
        $possible = 0.0;
        $scoredWeight = 0.0;
        $skipped = [];
        $breakdown = [];

        foreach ($this->criteria as $criterion) {
            if (! $criterion->scorable) {
                $skipped[$criterion->key] = $criterion->unscorableReason ?? 'not computable';

                continue;
            }

            $measurement = $measurements[$criterion->key] ?? null;

            // Unknown is not zero. `scorable` marks a criterion nothing can ever measure; this
            // is the per-entity case — this company has no sector on file, nobody set this
            // person's nivel. Scoring it as the bottom band rates an absence of evidence as bad
            // evidence, which is how 1,534 ejecutivos were marked lowest-level for a field that
            // simply said "Pendiente". A measured zero arrives as 0, not null, so "attended no
            // international events" still scores 0 on its own merits.
            if ($measurement === null) {
                $skipped[$criterion->key] = 'sin dato para esta entidad';

                continue;
            }

            $bandValue = $criterion->valueFor($measurement);
            $points = $bandValue * $criterion->weight;

            $earned += $points;
            // 40 is the top band value on every sheet, so the criterion's ceiling is 40 × weight.
            $possible += 40.0 * $criterion->weight;
            $scoredWeight += $criterion->weight;

            $breakdown[$criterion->key] = [
                'label' => $criterion->label,
                'measurement' => $measurements[$criterion->key] ?? null,
                'band_value' => $bandValue,
                'weight' => $criterion->weight,
                'points' => round($points, 4),
            ];
        }

        $percentage = $possible > 0.0 ? round(($earned / $possible) * 100.0, 2) : 0.0;

        return new ScoreResult(
            scorecard: $this->key,
            percentage: $percentage,
            letter: $this->letterFor($percentage),
            earned: round($earned, 4),
            possible: round($possible, 4),
            coverage: round($this->scorableWeight() * 100.0, 2),
            measured: round($scoredWeight * 100.0, 2),
            breakdown: $breakdown,
            skipped: $skipped,
        );
    }

    public function letterFor(float $percentage): string
    {
        foreach ($this->letters as $band) {
            if ($percentage >= $band['min']) {
                return $band['letter'];
            }
        }

        return 'E';
    }

    /**
     * Share of the card's nominal weight that can actually be measured. Below 1.0 the letter is
     * computed on a subset, which a caller may want to say out loud.
     */
    public function scorableWeight(): float
    {
        $total = 0.0;

        foreach ($this->criteria as $criterion) {
            if ($criterion->scorable) {
                $total += $criterion->weight;
            }
        }

        return $total;
    }
}
