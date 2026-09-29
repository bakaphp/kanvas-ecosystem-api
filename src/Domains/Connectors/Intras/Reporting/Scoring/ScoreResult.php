<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Reporting\Scoring;

/**
 * What a scorecard produced, and enough of its working to defend the number.
 *
 * The sheets ask for the drill-down explicitly — clicking an ejecutivo's arrows shows the same
 * table with only that person's earned points — so the per-criterion breakdown is part of the
 * result rather than something a caller has to recompute.
 */
final class ScoreResult
{
    /**
     * @param float                                    $coverage  share of the card's nominal weight that is
     *                                                            measurable at all, as a percentage — a property
     *                                                            of the card, identical for every entity
     * @param float                                    $measured  share actually measured for THIS entity, as a
     *                                                            percentage; below `coverage` when a field this
     *                                                            particular company or person left unset
     * @param array<string, array<string, mixed>>      $breakdown per-criterion working
     * @param array<string, string>                    $skipped   criterion key => why it could not be scored
     */
    public function __construct(
        public readonly string $scorecard,
        public readonly float $percentage,
        public readonly string $letter,
        public readonly float $earned,
        public readonly float $possible,
        public readonly float $coverage,
        public readonly float $measured = 0.0,
        public readonly array $breakdown = [],
        public readonly array $skipped = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scorecard' => $this->scorecard,
            'percentage' => $this->percentage,
            'letter' => $this->letter,
            'earned' => $this->earned,
            'possible' => $this->possible,
            'coverage' => $this->coverage,
            'measured' => $this->measured,
            'breakdown' => $this->breakdown,
            'skipped' => $this->skipped,
        ];
    }
}
