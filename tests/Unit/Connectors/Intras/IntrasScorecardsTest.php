<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\Intras;

use Kanvas\Connectors\Intras\Reporting\Scoring\Criterion;
use Kanvas\Connectors\Intras\Reporting\Scoring\IntrasScorecards;
use Kanvas\Connectors\Intras\Reporting\Scoring\Scorecard;
use PHPUnit\Framework\TestCase;

/**
 * The five INTRAS scorecards and the arithmetic the sheets specify.
 */
class IntrasScorecardsTest extends TestCase
{
    /**
     * Every sheet's weights add to 100%. A card that does not is a transcription error, and the
     * resulting letter would be wrong in a way nobody would notice by reading it.
     */
    public function testEveryScorecardWeightsToOneHundredPercent(): void
    {
        foreach (IntrasScorecards::all() as $card) {
            $total = 0.0;

            foreach ($card->criteria as $criterion) {
                $total += $criterion->weight;
            }

            $this->assertEqualsWithDelta(1.0, $total, 0.0001, $card->key . ' must weight to 100%');
        }
    }

    public function testTheLetterBandsMatchTheSheets(): void
    {
        $card = IntrasScorecards::ejecutivoClasificacion();

        $this->assertSame('A', $card->letterFor(100.0));
        $this->assertSame('A', $card->letterFor(80.0));
        $this->assertSame('B', $card->letterFor(79.99));
        $this->assertSame('B', $card->letterFor(55.0));
        $this->assertSame('C', $card->letterFor(54.0));
        $this->assertSame('C', $card->letterFor(31.0));
        $this->assertSame('D', $card->letterFor(30.0));
        $this->assertSame('D', $card->letterFor(11.0));
        $this->assertSame('E', $card->letterFor(10.0));
        $this->assertSame('E', $card->letterFor(0.0));
    }

    /**
     * The sheet's own worked example: top band on every criterion is 40 points, so a perfect
     * card is 100% and an A.
     */
    public function testAPerfectCardScoresOneHundred(): void
    {
        $card = IntrasScorecards::ejecutivoPotencialidad();

        $result = $card->score([
            'nivel' => 'VIP',
            'persona_clave' => 'SI',
            'clasificacion_empresa' => 'A',
            'potencialidad_empresa' => 'A',
        ]);

        $this->assertSame(100.0, $result->percentage);
        $this->assertSame('A', $result->letter);
        $this->assertSame(100.0, $result->coverage);
    }

    /**
     * "La puntuación obtenida es el resultado de multiplicar el valor del rango por el peso
     * general" — persona clave alone is 40 × 0.40 = 16 of a possible 40, i.e. 40%.
     */
    public function testASingleCriterionEarnsItsWeightedShare(): void
    {
        $result = IntrasScorecards::ejecutivoPotencialidad()->score([
            'nivel' => '4',
            'persona_clave' => 'SI',
            'clasificacion_empresa' => 'E',
            'potencialidad_empresa' => 'E',
        ]);

        $this->assertSame(16.0, $result->earned);
        $this->assertSame(40.0, $result->possible);
        $this->assertSame(40.0, $result->percentage);
        $this->assertSame('C', $result->letter);
    }

    /**
     * `scorable: false` marks a criterion nothing can ever measure. This is the per-entity case:
     * the card could measure it, this particular person has no value on file.
     *
     * Scoring that as the bottom band rates an absence of evidence as bad evidence — 1,534
     * ejecutivos were marked lowest-`nivel` because the field said "Pendiente", which means
     * nobody set it. It comes out of both halves of the fraction instead.
     */
    public function testAMissingMeasurementIsExcludedRatherThanScoredAsWorst(): void
    {
        $card = IntrasScorecards::ejecutivoPotencialidad();

        $withNivel = $card->score([
            'nivel' => 'NIVEL 4',
            'persona_clave' => 'SI',
            'clasificacion_empresa' => 'A',
            'potencialidad_empresa' => 'A',
        ]);

        $withoutNivel = $card->score([
            'nivel' => null,
            'persona_clave' => 'SI',
            'clasificacion_empresa' => 'A',
            'potencialidad_empresa' => 'A',
        ]);

        $this->assertArrayHasKey('nivel', $withoutNivel->skipped);
        $this->assertArrayNotHasKey('nivel', $withNivel->skipped);

        $this->assertSame(100.0, $withoutNivel->percentage, 'the rest of the card was perfect');
        $this->assertSame(75.0, $withNivel->percentage, 'NIVEL 4 is a real, measured zero');

        $this->assertSame(100.0, $withoutNivel->coverage, 'the card itself can still measure everything');
        $this->assertSame(75.0, $withoutNivel->measured, 'but only 75% of its weight was measured here');
    }

    /**
     * The distinction the whole rule rests on: a measured zero is not a missing measurement.
     * "Attended no international events" is a fact about the person and must still score 0.
     */
    public function testAMeasuredZeroStillScoresZero(): void
    {
        $result = IntrasScorecards::ejecutivoClasificacion()->score([
            'eventos_internacionales' => 0,
            'lealtad_internacionales' => 0,
            'eventos_evento_grande' => 0,
            'lealtad_evento_grande' => 0,
            'eventos_seminario' => 0,
            'lealtad_seminario' => 0,
            'eventos_in_house' => 0,
        ]);

        $this->assertSame([], array_diff_key($result->skipped, ['inversion_internacionales' => 1, 'inversion_evento_grande' => 1]));
        $this->assertSame(0.0, $result->percentage);
        $this->assertSame('E', $result->letter);
    }

    /**
     * A criterion with no usable input is excluded from both halves of the fraction. Scoring it
     * as zero would mark every ejecutivo down for a gap in SIPGO, not in their record.
     */
    public function testUnscorableCriteriaAreExcludedRatherThanZeroed(): void
    {
        $card = IntrasScorecards::ejecutivoClasificacion();
        $result = $card->score([
            'eventos_internacionales' => 99,
            'lealtad_internacionales' => 99,
            'eventos_evento_grande' => 99,
            'lealtad_evento_grande' => 99,
            'eventos_seminario' => 99,
            'lealtad_seminario' => 99,
            'eventos_in_house' => 99,
        ]);

        // Perfect on everything measurable, so still 100% — the two inversión criteria do not
        // drag it down.
        $this->assertSame(100.0, $result->percentage);
        $this->assertSame('A', $result->letter);

        // ...but the card says plainly that 11% of its nominal weight was not measured.
        $this->assertSame(89.0, $result->coverage);
        $this->assertArrayHasKey('inversion_internacionales', $result->skipped);
        $this->assertArrayHasKey('inversion_evento_grande', $result->skipped);
    }

    public function testTheEmpresaCardReportsItsThreePercentShortfall(): void
    {
        $result = IntrasScorecards::empresaClasificacion()->score([]);

        $this->assertSame(97.0, $result->coverage);
    }

    public function testThePibCriterionIsDefinedButInert(): void
    {
        $result = IntrasScorecards::empresaPotencialidad()->score(['tamano' => 'GRANDE']);

        $this->assertArrayHasKey('pib_sector', $result->skipped);
        $this->assertSame(95.0, $result->coverage);
    }

    public function testAMeasurementOutsideEveryBandScoresZero(): void
    {
        $card = IntrasScorecards::ejecutivoClasificacion();

        $this->assertSame(0.0, $card->criteria[0]->valueFor(0));
        $this->assertSame(40.0, $card->criteria[0]->valueFor(6));
    }

    /**
     * The sheet spells the levels "1".."4" and "VIP +"; the column says "NIVEL 1".."NIVEL 4" and
     * "VIP PLUS". Matching only the sheet's spelling scored 54,558 of 55,045 ejecutivos at zero
     * on a criterion worth a quarter of the card — 0.9% matched — so well-known executives came
     * out as C and D. Both spellings are accepted: the sheet is the specification, the column is
     * the data, and neither gets to be wrong.
     */
    public function testTheLevelCriterionAcceptsHowTheDataActuallySpellsIt(): void
    {
        $nivel = $this->criterion('ejecutivo_potencialidad', 'nivel');

        foreach (['NIVEL 1' => 30.0, 'NIVEL 2' => 20.0, 'NIVEL 3' => 10.0, 'NIVEL 4' => 0.0] as $stored => $expected) {
            $this->assertSame($expected, $nivel->valueFor($stored), $stored . ' is how the column spells it');
        }

        foreach (['1' => 30.0, '2' => 20.0, '3' => 10.0, '4' => 0.0] as $sheet => $expected) {
            $this->assertSame($expected, $nivel->valueFor($sheet), $sheet . ' is how the sheet spells it');
        }

        $this->assertSame(40.0, $nivel->valueFor('VIP'));
        $this->assertSame(40.0, $nivel->valueFor('VIP PLUS'), 'the column spelling of "VIP +"');
        $this->assertSame(40.0, $nivel->valueFor('VIP +'));
    }

    /**
     * SIPGO's placeholder for "not specified", kept verbatim in the flat tables rather than
     * nulled at ingest. It is not a level, so it earns nothing.
     */
    public function testPendienteIsNotALevel(): void
    {
        $this->assertSame(0.0, $this->criterion('ejecutivo_potencialidad', 'nivel')->valueFor('Pendiente'));
    }

    public function testAnUnknownCategoricalValueScoresZeroRatherThanThrowing(): void
    {
        $card = IntrasScorecards::ejecutivoPotencialidad();

        $this->assertSame(0.0, $card->criteria[0]->valueFor('NO SUCH LEVEL'));
        $this->assertSame(0.0, $card->criteria[0]->valueFor(null));
    }

    /**
     * A band list is searched top-down for the first floor the measurement clears, so a list that
     * is not ordered highest-floor-first silently returns a lower band than it should, and one
     * without a `min: null` catch-all drops small values to 0 through the fallthrough.
     *
     * This is the structural half of the bug that made nine years of loyalty score 0: bands used
     * to carry a ceiling as well, and a ceiling that stopped short of the next floor left a hole.
     * Ceilings are gone, so the only way back to a hole is a mis-ordered list.
     */
    public function testEveryBandListIsOrderedHighestFloorFirstAndEndsOpen(): void
    {
        foreach (IntrasScorecards::all() as $card) {
            foreach ($card->criteria as $criterion) {
                if ($criterion->bands === []) {
                    continue;
                }

                $where = $card->key . '.' . $criterion->key;
                $floors = array_column($criterion->bands, 'min');
                $last = array_key_last($floors);

                $this->assertNull($floors[$last], "{$where}: the last band must be the open catch-all");

                $declared = array_slice($floors, 0, $last);
                $sorted = $declared;
                rsort($sorted);

                $this->assertSame($sorted, $declared, "{$where}: bands must be ordered highest floor first");
                $this->assertSame(count(array_unique($declared)), count($declared), "{$where}: duplicate floor");
            }
        }
    }

    /**
     * The behavioural half, pinned on the exact values that used to fall through. Nine years of
     * loyalty scored 0 — below a single year — because the 30 band stopped at 8.99 and the 40
     * band began at 10.
     */
    public function testLoyaltyYearsInTheOldGapNowScoreTheirBand(): void
    {
        $lealtad = $this->criterion('empresa_clasificacion', 'lealtad_seminario');

        $this->assertSame(30.0, $lealtad->valueFor(9), 'nine years used to fall through to 0');
        $this->assertSame(30.0, $lealtad->valueFor(7));
        $this->assertSame(40.0, $lealtad->valueFor(10));
        // The empresa seminar band deliberately starts at 2 ("2-3 AÑOS" on the sheet), unlike the
        // shared loyalty table, so one year earning nothing here is correct rather than a hole.
        $this->assertSame(10.0, $lealtad->valueFor(2));
        $this->assertSame(0.0, $lealtad->valueFor(1));

        $inHouse = $this->criterion('empresa_clasificacion', 'lealtad_in_house');

        $this->assertSame(30.0, $inHouse->valueFor(4), 'four years used to fall through to 0');
        $this->assertSame(40.0, $inHouse->valueFor(5));

        $inversion = $this->criterion('empresa_clasificacion', 'inversion_propuestas_in_house');

        // Exactly 20,000 used to score 0 — the 30 band stopped at 19,999 and the 40 band began at
        // 20,001. It now lands in the 30 band. Whether the sheet means the top band to start AT
        // 20,000 or above it is a question for INTRAS; either way it is no longer a hole.
        $this->assertSame(30.0, $inversion->valueFor(20000), 'must not fall through to 0');
        $this->assertSame(30.0, $inversion->valueFor(19999.50));
        $this->assertSame(40.0, $inversion->valueFor(20001));
    }

    /**
     * No measurement may reach the fallthrough unless it is genuinely below the lowest band —
     * swept over a range that covers counts, years, percentages and the money criteria.
     */
    public function testNoMeasurementFallsThroughEveryBand(): void
    {
        foreach (IntrasScorecards::all() as $card) {
            foreach ($card->criteria as $criterion) {
                if ($criterion->bands === []) {
                    continue;
                }

                $lowestFloor = min(array_filter(
                    array_column($criterion->bands, 'min'),
                    static fn (?float $min): bool => $min !== null
                ));

                $sweep = array_filter(
                    [$lowestFloor, $lowestFloor + 0.5, 9, 20000, 34.995, 1000000],
                    static fn (float|int $measurement): bool => $measurement >= $lowestFloor
                );

                foreach ($sweep as $measurement) {
                    $this->assertGreaterThan(
                        0.0,
                        $criterion->valueFor($measurement),
                        sprintf('%s.%s: %s matched no band', $card->key, $criterion->key, $measurement)
                    );
                }
            }
        }
    }

    private function criterion(string $card, string $key): Criterion
    {
        foreach (IntrasScorecards::byKey($card)?->criteria ?? [] as $criterion) {
            if ($criterion->key === $key) {
                return $criterion;
            }
        }

        $this->fail("No criterion {$key} on {$card}.");
    }

    public function testByKeyFindsEachCardAndReturnsNullOtherwise(): void
    {
        foreach (['ejecutivo_clasificacion', 'ejecutivo_potencialidad', 'empresa_clasificacion', 'empresa_potencialidad', 'evento_clasificacion'] as $key) {
            $this->assertInstanceOf(Scorecard::class, IntrasScorecards::byKey($key));
        }

        $this->assertNull(IntrasScorecards::byKey('nope'));
    }

    /**
     * The sheet's ranges share edges and leave gaps. Read as floors, a shared edge takes the
     * higher band, a gap falls to the band below, and ">25" only starts above 25.
     */
    public function testTheEventCardBandsReadTheSheetsEdgesAsFloors(): void
    {
        $band = fn (string $key, float $value): float => $this->criterion('evento_clasificacion', $key)->valueFor($value);

        $this->assertSame(40.0, $band('promedio_participantes', 25.1));
        $this->assertSame(30.0, $band('promedio_participantes', 25.0));
        $this->assertSame(30.0, $band('promedio_participantes', 23.0));
        $this->assertSame(20.0, $band('promedio_participantes', 22.9));
        $this->assertSame(10.0, $band('promedio_participantes', 18.5));
        $this->assertSame(10.0, $band('promedio_participantes', 12.0));
        $this->assertSame(0.0, $band('promedio_participantes', 11.9));

        $this->assertSame(40.0, $band('satisfaccion', 4.9));
        $this->assertSame(30.0, $band('satisfaccion', 4.85));
        $this->assertSame(20.0, $band('satisfaccion', 4.6));
        $this->assertSame(10.0, $band('satisfaccion', 4.1));
        $this->assertSame(0.0, $band('satisfaccion', 4.05));
    }

    /**
     * 24 participants on average (30 × 60%) and 4.9 satisfaction (40 × 40%) is 34 of 40: 85%, an A.
     */
    public function testAnEventScoresItsTwoCriteriaByWeight(): void
    {
        $result = IntrasScorecards::eventoClasificacion()->score([
            'promedio_participantes' => 24.0,
            'satisfaccion' => 4.9,
        ]);

        $this->assertSame(85.0, $result->percentage);
        $this->assertSame('A', $result->letter);
    }

    /**
     * An event with no evaluations yet is scored on attendance alone, not marked down to E.
     */
    public function testAnEventWithoutEvaluationsIsScoredOnAttendanceAlone(): void
    {
        $result = IntrasScorecards::eventoClasificacion()->score([
            'promedio_participantes' => 26.0,
            'satisfaccion' => null,
        ]);

        $this->assertSame(100.0, $result->percentage);
        $this->assertArrayHasKey('satisfaccion', $result->skipped);
    }
}
