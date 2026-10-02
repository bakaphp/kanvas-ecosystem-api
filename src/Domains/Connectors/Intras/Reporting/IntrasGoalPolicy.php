<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Reporting;

/**
 * INTRAS's own definition of "on track" for an event version.
 *
 * Both numbers below come from INTRAS documents — "REPORTE ESTADÍSTICO — VERSIONES DE EVENTOS"
 * and "CALENDARIO — ESTADÍSTICAS" — and describe how *this* business judges enrolment. They are
 * not platform semantics, so they live here rather than in `Kanvas\Event`, where they would
 * silently reprice every other tenant's goal tracking.
 *
 * `Kanvas\Event\Reports\Services\GoalTrackingService` keeps its own generic seven-week curve and
 * raw headcount, and is left untouched. The consequence is deliberate and worth stating: the
 * CRM's `/reportes` page calls the platform queries, so it keeps the platform curve until those
 * resolvers are pointed at something INTRAS-aware. Everything on the INTRAS reporting side —
 * the flat tables and the agent tools — reads this class.
 */
final class IntrasGoalPolicy
{
    /**
     * Share of the goal expected to arrive *in* each week before the event (chart 1).
     *
     * The five shares sum to 100% and their running total is exactly CUMULATIVE below. The two
     * curves in the spec are one curve read per-week and cumulatively, which is how we know
     * neither is a transcription error.
     */
    public const array WEEKLY_SHARE = [
        5 => 0.25,
        4 => 0.20,
        3 => 0.30,
        2 => 0.15,
        1 => 0.10,
    ];

    /**
     * Share of the goal expected to have been reached *by* each week (chart 2, and the identical
     * curve in CALENDARIO — ESTADÍSTICAS, which is what the rojo/verde banding means).
     *
     * The platform's generic curve is a seven-week 5/10/20/40/60/80/100 that matches neither
     * document: at three weeks out it expects 60% where INTRAS expects 75%, so an event at 65%
     * of goal reads green when INTRAS would call it red.
     */
    public const array CUMULATIVE_SHARE = [
        5 => 0.25,
        4 => 0.45,
        3 => 0.75,
        2 => 0.90,
        1 => 1.00,
    ];

    /**
     * Days-before-event that define each week bucket.
     */
    public const array WEEK_BUCKETS = [
        5 => ['min_days' => 29, 'max_days' => null],
        4 => ['min_days' => 22, 'max_days' => 28],
        3 => ['min_days' => 15, 'max_days' => 21],
        2 => ['min_days' => 8, 'max_days' => 14],
        1 => ['min_days' => 1, 'max_days' => 7],
    ];

    /**
     * How much each inscription type contributes to the "inscritos" curve.
     *
     * The spec defines the total as an *expected attendance* estimate rather than a headcount:
     * `0.95×(Confirmado + Confirmado Plan + Programa + Intercambio + Crédito) + 0.6×Reservados`.
     * 0.95 is the assumed no-show allowance; softer commitments count at 0.6. A type absent from
     * this map contributes nothing — CANCELADO, CORTESÍA, INVITADO - SPONSOR and EMPLEADO(A) are
     * not expected attendance.
     *
     * Chart 1 words the 0.6 bucket as "Reservados" and chart 2 as "Interesados"; both exist as
     * types and both are soft commitments, so both are weighted.
     *
     * `INTERESADO EVENTO` sits in that bucket too, at the same 0.6, and the reason is that it is
     * indistinguishable from the two the documents do name. Across app 49:
     *
     * | type              | rows  | converted in place | person ever participated | since 2024 |
     * |-------------------|-------|--------------------|--------------------------|------------|
     * | INTERESADO EVENTO | 1,321 | 0.0%               | 73.0%                    | 177        |
     * | INTERESADO        |   537 | 0.7%               | 76.5%                    |  34        |
     * | RESERVADO         |    57 | 0.0%               | 56.1%                    |   1        |
     *
     * Same behaviour, same liveness, three names. It was previously excluded because the string
     * does not literally appear in either document — which left the largest soft-commitment type
     * at 0 while the two smallest counted at 0.6, a split nothing in the data supports.
     *
     * **The 0% column is not evidence for a weight of 0.** SIPGO updates the type in place rather
     * than appending a row, so a registration that firms up is relabelled CONFIRMADO and leaves
     * this bucket entirely. What survives as INTERESADO is exactly what never converted, and
     * CANCELADO scores 0.0% on the same measure. Closed-event history therefore cannot estimate
     * the forward conversion rate the curve needs; 0.6 is the documented figure for a soft
     * commitment and is applied uniformly.
     */
    public const array TYPE_WEIGHTS = [
        'confirmado' => 0.95,
        'confirmado-plan' => 0.95,
        'programa' => 0.95,
        'intercambio' => 0.95,
        'credito' => 0.95,
        'reservado' => 0.60,
        'interesado' => 0.60,
        'interesado-evento' => 0.60,
    ];

    /**
     * The inscription types INTRAS counts as actual participation, as used by every headcount
     * question the business asks. Distinct from the weights above, which estimate attendance
     * for a goal curve — this is the yes/no membership test.
     */
    public const array PARTICIPATION_TYPES = ['CONFIRMADO', 'CONFIRMADO PLAN', 'PROGRAMA'];

    /**
     * A cancelled version counts toward nothing — no headcount, no goal, no ranking.
     *
     * Named here rather than written at each filter because version statuses are matched by
     * **name**, not id: `events_versions_statuses` carries duplicate rows (two "Pendiente", ids 1
     * and 5), so the importer resolves them by name and this string is what the flat tables hold.
     * A rename in SIPGO therefore stops every exclusion silently — the queries still run, they
     * just stop excluding anything.
     */
    public const string CANCELLED_STATUS = 'Cancelado';

    /**
     * ESTRATEGIAS DE COMUNICACIÓN: an open seminar with fewer than `below` firm registrations
     * `days` days out gets the plan. Plan C may only run once every 30 days, which nothing here
     * can know — campaigns sent are not recorded anywhere.
     */
    public const array PLAN_B = ['days' => 14, 'below' => 18];
    public const array PLAN_C = ['days' => 10, 'below' => 15];

    /**
     * "Participantes inscritos" for the Plan B / Plan C triggers: the firm commitments, i.e. the
     * types carrying the top weight in TYPE_WEIGHTS. A soft interest is not a seat, and counting it
     * would hold back the very campaign meant to fill the room.
     *
     * @return list<string>
     */
    public static function firmTypes(): array
    {
        return array_keys(self::TYPE_WEIGHTS, max(self::TYPE_WEIGHTS), true);
    }

    /**
     * @param array<string, int> $countsByTypeSlug
     */
    public static function firmCount(array $countsByTypeSlug): int
    {
        return array_sum(array_intersect_key($countsByTypeSlug, array_flip(self::firmTypes())));
    }

    /**
     * Two states, not three.
     *
     * CALENDARIO — ESTADÍSTICAS is explicit: "franja roja cuando la cantidad de participantes sea
     * menor a la cantidad esperada para esa fecha y verde cuando sea igual o por encima". The
     * platform bands at 86% and 70% and shows an amber middle, which is where the CRM's
     * "Atención" state comes from — it is not in the INTRAS document.
     */
    public static function colorFor(int $actual, int $expected): string
    {
        if ($expected <= 0) {
            return 'verde';
        }

        return $actual >= $expected ? 'verde' : 'rojo';
    }

    public static function expectedEnrolment(int $goal, int $weeksUntilEvent, bool $cumulative = true): int
    {
        if ($goal <= 0) {
            return 0;
        }

        $curve = $cumulative ? self::CUMULATIVE_SHARE : self::WEEKLY_SHARE;

        return (int) round((float) $goal * ($curve[max(1, min(5, $weeksUntilEvent))] ?? 1.0));
    }

    /**
     * Expected attendance for a set of `type slug => count` pairs.
     *
     * @param array<string, int> $countsByTypeSlug
     */
    public static function weightedTotal(array $countsByTypeSlug): int
    {
        $total = 0.0;

        foreach ($countsByTypeSlug as $slug => $count) {
            $total += (float) $count * (self::TYPE_WEIGHTS[$slug] ?? 0.0);
        }

        return (int) round($total);
    }
}
