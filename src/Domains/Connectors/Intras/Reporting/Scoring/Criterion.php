<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Reporting\Scoring;

/**
 * One row of an INTRAS scorecard: a measurement, the bands it falls into, and how much the
 * band's value counts towards the final percentage.
 *
 * The sheets are explicit that all four of these are editable — "Debemos poder modificar los
 * criterios, rangos, valor por rango, peso general del criterio" — so nothing here is a
 * constant. `IntrasScorecards` supplies the documented defaults and a stored override replaces
 * them wholesale.
 */
final class Criterion
{
    /**
     * @param string                                        $key      what `Scorecard::score()` looks up in the value bag
     * @param float                                         $weight   share of the final score, 0..1
     * @param list<array{min: float|null, value: float}>    $bands    numeric bands, highest floor first
     * @param array<string, float>                          $matches  exact-value lookup for categorical criteria
     * @param bool                                          $scorable false when the input does not exist yet, so the
     *                                                                criterion is reported rather than silently zeroed
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly float $weight,
        public readonly array $bands = [],
        public readonly array $matches = [],
        public readonly bool $scorable = true,
        public readonly ?string $unscorableReason = null,
    ) {
    }

    /**
     * The band value (0..40 in every INTRAS sheet) this measurement earns.
     *
     * A categorical criterion matches on the normalised string; a numeric one takes the first
     * band whose floor it clears, which is why `$bands` must be ordered highest floor first and
     * end with a `min: null` catch-all.
     *
     * A band carries only its floor, never a ceiling. A ceiling and the next band's floor encode
     * the same boundary twice, and two encodings of one number drift — a ceiling of `8.99` under
     * a next floor of `10.0` leaves 9 matching nothing and falling through to 0, scoring a
     * nine-year client below a one-year one. With floors alone that gap cannot be expressed.
     */
    public function valueFor(float|string|null $measurement): float
    {
        if ($measurement === null) {
            return 0.0;
        }

        if ($this->matches !== []) {
            return $this->matches[mb_strtoupper(trim((string) $measurement))] ?? 0.0;
        }

        $number = (float) $measurement;

        foreach ($this->bands as $band) {
            if ($band['min'] === null || $number >= $band['min']) {
                return $band['value'];
            }
        }

        return 0.0;
    }
}
