<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Reporting\Scoring;

/**
 * The four INTRAS scorecards, as specified.
 *
 * Sources: "EJECUTIVOS - CLASIFICACIÓN INTERNA", "EJECUTIVOS - POTENCIALIDAD EXTERNA",
 * "EMPRESA - CLASIFICACIÓN INTERNA", "EMPRESA - POTENCIALIDAD EXTERNA". Every weight, band and
 * cutoff below is transcribed from those sheets; the weights on each card sum to 100%.
 *
 * These are defaults, not law — all four sheets open with "Debemos poder modificar los criterios,
 * rangos, valor por rango, peso general", so a stored override replaces a card wholesale.
 *
 * Two criteria are marked unscorable rather than scored as zero:
 *
 * - **Inversión histórica acumulada.** SIPGO holds an `investment` figure on 2,106 of agency 1's
 *   66,197 registrations — 3%. Our import matches that exactly, so this is the source's gap, not
 *   the migration's. Scoring it as zero would mark down every ejecutivo and empresa alike.
 * - **PIB por sector.** No such data exists anywhere in SIPGO, and the 44 sector names are
 *   INTRAS's own taxonomy ("ZONA FRANCA MANUFACTURA", "PUBLICIDAD Y RELACIONES PUBLICAS"), not
 *   national-accounts categories. Mapping them onto official GDP shares is a business judgement
 *   that moves scores, so the band table is here and waiting for the numbers.
 *
 * `Scorecard::score()` drops both from numerator *and* denominator and reports the shortfall as
 * `coverage`, so the letter stays honest about what it was computed from.
 */
final class IntrasScorecards
{
    /** Money bands shared by the inversión criteria on the ejecutivo card. */
    private const array INVERSION_BANDS = [
        ['min' => 7001.0, 'value' => 40.0],
        ['min' => 3001.0, 'value' => 30.0],
        ['min' => 1001.0, 'value' => 20.0],
        ['min' => 1.0, 'value' => 10.0],
        ['min' => null, 'value' => 0.0],
    ];

    /**
     * Loyalty bands — "# de años de inversión" — shared by six criteria across both cards.
     *
     * Read as: 10+ years is 40, 7-9 is 30, 4-6 is 20, 1-3 is 10, none is 0. Only the floor is
     * declared — see `Criterion::valueFor()` for why carrying a ceiling as well was a bug.
     */
    private const array LEALTAD_BANDS = [
        ['min' => 10.0, 'value' => 40.0],
        ['min' => 7.0, 'value' => 30.0],
        ['min' => 4.0, 'value' => 20.0],
        ['min' => 1.0, 'value' => 10.0],
        ['min' => null, 'value' => 0.0],
    ];

    /** A / B / C / D / E rating of a related entity. */
    private const array LETTER_MATCHES = ['A' => 40.0, 'B' => 30.0, 'C' => 20.0, 'D' => 10.0, 'E' => 0.0];

    private const string INVERSION_UNSCORABLE =
        'SIPGO records an investment amount on ~3% of registrations, so the criterion has no '
        . 'usable input. Excluded from the score rather than counted as zero.';

    private const string PIB_UNSCORABLE =
        'No PIB share per sector exists in SIPGO, and mapping the 44 INTRAS sector names onto '
        . 'national-accounts categories is a business decision. Bands are defined and inert '
        . 'until the percentages are supplied.';

    /**
     * @return list<Scorecard>
     */
    public static function all(): array
    {
        return [
            self::ejecutivoClasificacion(),
            self::ejecutivoPotencialidad(),
            self::empresaClasificacion(),
            self::empresaPotencialidad(),
        ];
    }

    public static function byKey(string $key): ?Scorecard
    {
        foreach (self::all() as $card) {
            if ($card->key === $key) {
                return $card;
            }
        }

        return null;
    }

    /**
     * Weights: 3 + 1 + 1 + 10 + 15 + 10 + 40 + 15 + 5 = 100%.
     */
    public static function ejecutivoClasificacion(): Scorecard
    {
        return new Scorecard(
            key: 'ejecutivo_clasificacion',
            label: 'Ejecutivos — Clasificación Interna',
            criteria: [
                new Criterion(
                    key: 'eventos_internacionales',
                    label: '# de eventos histórico — internacionales',
                    weight: 0.03,
                    bands: self::countBands(6, 3, 2, 1),
                ),
                new Criterion(
                    key: 'inversion_internacionales',
                    label: 'Inversión histórica acumulada — internacionales',
                    weight: 0.01,
                    bands: self::INVERSION_BANDS,
                    scorable: false,
                    unscorableReason: self::INVERSION_UNSCORABLE,
                ),
                new Criterion(
                    key: 'lealtad_internacionales',
                    label: '# de años de inversión — internacionales',
                    weight: 0.01,
                    bands: self::LEALTAD_BANDS,
                ),
                new Criterion(
                    key: 'inversion_evento_grande',
                    label: 'Inversión histórica acumulada — eventos grandes',
                    weight: 0.10,
                    bands: self::INVERSION_BANDS,
                    scorable: false,
                    unscorableReason: self::INVERSION_UNSCORABLE,
                ),
                new Criterion(
                    key: 'eventos_evento_grande',
                    label: '# de eventos histórico — eventos grandes',
                    weight: 0.15,
                    bands: self::countBands(11, 5, 3, 1),
                ),
                new Criterion(
                    key: 'lealtad_evento_grande',
                    label: '# de años de inversión — eventos grandes',
                    weight: 0.10,
                    bands: self::LEALTAD_BANDS,
                ),
                new Criterion(
                    key: 'eventos_seminario',
                    label: '# de eventos histórico — seminarios',
                    weight: 0.40,
                    bands: self::countBands(11, 5, 3, 1),
                ),
                new Criterion(
                    key: 'lealtad_seminario',
                    label: '# de años de inversión — seminarios',
                    weight: 0.15,
                    bands: self::LEALTAD_BANDS,
                ),
                new Criterion(
                    key: 'eventos_in_house',
                    label: '# de eventos histórico — in-house',
                    weight: 0.05,
                    bands: self::LEALTAD_BANDS,
                ),
            ],
        );
    }

    /**
     * Weights: 25 + 40 + 30 + 5 = 100%.
     */
    public static function ejecutivoPotencialidad(): Scorecard
    {
        return new Scorecard(
            key: 'ejecutivo_potencialidad',
            label: 'Ejecutivos — Potencialidad Externa',
            criteria: [
                new Criterion(
                    key: 'nivel',
                    label: 'Nivel',
                    weight: 0.25,
                    // Transcribed from the sheet as bare "1".."4" and "VIP +". The data says
                    // "NIVEL 1".."NIVEL 4" and "VIP PLUS", so only 487 of 55,045 ejecutivos —
                    // 0.9%, the ones spelled exactly "VIP" — matched anything. Everyone else
                    // scored 0 on a quarter of this card, which is why well-known executives
                    // came out as C and D. Both spellings are accepted rather than picking one,
                    // because the sheet is the specification and the column is the data.
                    matches: [
                        'VIP' => 40.0,
                        'VIP +' => 40.0,
                        'VIP+' => 40.0,
                        'VIP PLUS' => 40.0,
                        '1' => 30.0,
                        '2' => 20.0,
                        '3' => 10.0,
                        '4' => 0.0,
                        'NIVEL 1' => 30.0,
                        'NIVEL 2' => 20.0,
                        'NIVEL 3' => 10.0,
                        'NIVEL 4' => 0.0,
                    ],
                ),
                new Criterion(
                    key: 'persona_clave',
                    label: 'Persona clave',
                    weight: 0.40,
                    matches: ['SI' => 40.0, 'SÍ' => 40.0, '1' => 40.0, 'NO' => 0.0, '0' => 0.0],
                ),
                new Criterion(
                    key: 'clasificacion_empresa',
                    label: 'Clasificación interna de la empresa',
                    weight: 0.30,
                    matches: self::LETTER_MATCHES,
                ),
                new Criterion(
                    key: 'potencialidad_empresa',
                    label: 'Potencialidad externa de la empresa',
                    weight: 0.05,
                    matches: self::LETTER_MATCHES,
                ),
            ],
        );
    }

    /**
     * Weights: 1 + 1 + 1 + 6 + 2 + 6 + 30 + 15 + 5 + 2 + 19 + 6 + 6 = 100%.
     */
    public static function empresaClasificacion(): Scorecard
    {
        return new Scorecard(
            key: 'empresa_clasificacion',
            label: 'Empresa — Clasificación Interna',
            criteria: [
                new Criterion(
                    key: 'participantes_internacionales',
                    label: '# de participantes histórico — internacionales',
                    weight: 0.01,
                    bands: self::countBands(21, 15, 6, 1),
                ),
                new Criterion(
                    key: 'inversion_internacionales',
                    label: 'Inversión acumulada de ejecutivos — internacionales',
                    weight: 0.01,
                    bands: self::empresaMoneyBands(),
                    scorable: false,
                    unscorableReason: self::INVERSION_UNSCORABLE,
                ),
                new Criterion(
                    key: 'lealtad_internacionales',
                    label: '# de años de inversión — internacionales',
                    weight: 0.01,
                    bands: self::LEALTAD_BANDS,
                ),
                new Criterion(
                    key: 'participantes_evento_grande',
                    label: '# de participantes histórico — eventos grandes',
                    weight: 0.06,
                    bands: self::countBands(121, 50, 10, 1),
                ),
                new Criterion(
                    key: 'inversion_evento_grande',
                    label: 'Inversión acumulada — eventos grandes',
                    weight: 0.02,
                    bands: self::empresaMoneyBands(),
                    scorable: false,
                    unscorableReason: self::INVERSION_UNSCORABLE,
                ),
                new Criterion(
                    key: 'lealtad_evento_grande',
                    label: '# de años de inversión — eventos grandes',
                    weight: 0.06,
                    bands: self::LEALTAD_BANDS,
                ),
                new Criterion(
                    key: 'participantes_seminario',
                    label: '# de participantes histórico — seminarios',
                    weight: 0.30,
                    bands: self::countBands(201, 100, 20, 1),
                ),
                new Criterion(
                    key: 'lealtad_seminario',
                    label: '# de años de inversión — seminarios',
                    weight: 0.15,
                    // This one band differs from the shared loyalty table: the sheet says
                    // "2-3 AÑOS" for the 10-point band, not 1-3.
                    bands: [
                        ['min' => 10.0, 'value' => 40.0],
                        ['min' => 7.0, 'value' => 30.0],
                        ['min' => 4.0, 'value' => 20.0],
                        ['min' => 2.0, 'value' => 10.0],
                        ['min' => null, 'value' => 0.0],
                    ],
                ),
                new Criterion(
                    key: 'cliente_axis',
                    label: 'Cliente AXIS (tipo de plan)',
                    weight: 0.05,
                    matches: ['SI' => 40.0, 'SÍ' => 40.0, '1' => 40.0, 'NO' => 0.0, '0' => 0.0],
                ),
                new Criterion(
                    key: 'eventos_outsourcing',
                    label: 'Eventos — outsourcing de eventos',
                    weight: 0.02,
                    bands: [
                        ['min' => 2.0, 'value' => 40.0],
                        ['min' => null, 'value' => 0.0],
                    ],
                ),
                new Criterion(
                    key: 'inversion_propuestas_in_house',
                    label: 'Inversión acumulada de propuestas in-house aprobadas',
                    weight: 0.19,
                    bands: [
                        ['min' => 20001.0, 'value' => 40.0],
                        ['min' => 10000.0, 'value' => 30.0],
                        ['min' => 6001.0, 'value' => 20.0],
                        ['min' => 0.01, 'value' => 10.0],
                        ['min' => null, 'value' => 0.0],
                    ],
                ),
                new Criterion(
                    key: 'propuestas_in_house_aprobadas',
                    label: 'Cantidad de propuestas in-house aprobadas',
                    weight: 0.06,
                    bands: self::countBands(16, 10, 5, 1),
                ),
                new Criterion(
                    key: 'lealtad_in_house',
                    label: '# de años de inversión — in-house',
                    weight: 0.06,
                    bands: [
                        ['min' => 5.0, 'value' => 40.0],
                        ['min' => 3.0, 'value' => 30.0],
                        ['min' => 2.0, 'value' => 20.0],
                        ['min' => 1.0, 'value' => 10.0],
                        ['min' => null, 'value' => 0.0],
                    ],
                ),
            ],
        );
    }

    /**
     * Weights: 40 + 5 + 20 + 20 + 15 = 100%.
     */
    public static function empresaPotencialidad(): Scorecard
    {
        $sharePercentBands = [
            ['min' => 35.0, 'value' => 40.0],
            ['min' => 25.0, 'value' => 30.0],
            ['min' => 15.0, 'value' => 20.0],
            ['min' => 1.0, 'value' => 10.0],
            ['min' => null, 'value' => 0.0],
        ];

        return new Scorecard(
            key: 'empresa_potencialidad',
            label: 'Empresa — Potencialidad Externa',
            criteria: [
                new Criterion(
                    key: 'penetracion_sector',
                    label: 'Sector empresarial (interna) — % de ejecutivos del sector con eventos',
                    weight: 0.40,
                    bands: $sharePercentBands,
                ),
                new Criterion(
                    key: 'pib_sector',
                    label: 'Sector empresarial (externa) — % del PIB nacional',
                    weight: 0.05,
                    bands: [
                        ['min' => 10.0, 'value' => 40.0],
                        ['min' => 6.0, 'value' => 30.0],
                        ['min' => 3.0, 'value' => 20.0],
                        ['min' => 1.0, 'value' => 10.0],
                        ['min' => null, 'value' => 0.0],
                    ],
                    scorable: false,
                    unscorableReason: self::PIB_UNSCORABLE,
                ),
                new Criterion(
                    key: 'penetracion_tipo',
                    label: 'Tipo — % de ejecutivos del tipo con eventos',
                    weight: 0.20,
                    bands: $sharePercentBands,
                ),
                new Criterion(
                    key: 'penetracion_ciudad',
                    label: 'Ciudad — % de ejecutivos de la ciudad con eventos',
                    weight: 0.20,
                    // The sheet prints US$ amounts against this criterion, but its own note
                    // describes a percentage of ejecutivos by city — the money column is a
                    // copy/paste from the row above. The note wins; flag it if they disagree.
                    bands: $sharePercentBands,
                ),
                new Criterion(
                    key: 'tamano',
                    label: 'Tamaño de la empresa',
                    weight: 0.15,
                    matches: ['GRANDE' => 40.0, 'MEDIANA' => 30.0, 'PEQUEÑA' => 20.0, 'PEQUENA' => 20.0],
                ),
            ],
        );
    }

    /**
     * Four descending count bands worth 40/30/20/10, and 0 below the lowest. Each argument is
     * the band's floor, so they must be passed highest first.
     *
     * @return list<array{min: float|null, value: float}>
     */
    private static function countBands(
        int $top,
        int $high,
        int $mid,
        int $low
    ): array {
        return [
            ['min' => (float) $top, 'value' => 40.0],
            ['min' => (float) $high, 'value' => 30.0],
            ['min' => (float) $mid, 'value' => 20.0],
            ['min' => (float) $low, 'value' => 10.0],
            ['min' => null, 'value' => 0.0],
        ];
    }

    /**
     * @return list<array{min: float|null, value: float}>
     */
    private static function empresaMoneyBands(): array
    {
        return [
            ['min' => 10000.0, 'value' => 40.0],
            ['min' => 6001.0, 'value' => 30.0],
            ['min' => 3000.0, 'value' => 20.0],
            ['min' => 1.0, 'value' => 10.0],
            ['min' => null, 'value' => 0.0],
        ];
    }
}
