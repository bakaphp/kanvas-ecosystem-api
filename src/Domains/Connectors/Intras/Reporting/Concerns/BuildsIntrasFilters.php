<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Reporting\Concerns;

use Kanvas\Analytics\Reporting\DataTransferObject\ReportFilter;
use Kanvas\Connectors\Intras\Reporting\IntrasGoalPolicy;

/**
 * The filters every INTRAS reporting tool needs, in one place.
 *
 * Two of them are not optional and are the reason these tools exist rather than leaving the
 * agent to compose `run_report` calls:
 *
 * - **"Participó" is not "has a row in `inscripcion`."** It means `tipo_inscripcion IN
 *   (CONFIRMADO, CONFIRMADO PLAN, PROGRAMA)`. The table also holds CANCELADO (2,400 rows in
 *   agency 1), INTERESADO EVENTO (1,310) and INVITADO - SPONSOR (597), none of which are
 *   participation.
 * - **Cancelled versions do not count.** 1,925 of agency 1's registrations sit on versions
 *   cancelled in SIPGO.
 *
 * Together those two are worth 30%: the same question — distinct ejecutivos in 2025 open
 * seminars — answers 1,053 without them and 812 with. Silently, with no error. Encoding them
 * here makes the wrong answer unreachable rather than something each tool has to remember.
 */
trait BuildsIntrasFilters
{
    /**
     * @return array<int, ReportFilter>
     */
    protected function participationFilters(
        ?string $tipo = null,
        ?string $clase = null,
        ?string $categoria = null,
        ?string $lineaTematica = null,
    ): array {
        $filters = [
            new ReportFilter('tipo_inscripcion', 'IN', IntrasGoalPolicy::PARTICIPATION_TYPES),
            new ReportFilter('estatus_version', '!=', IntrasGoalPolicy::CANCELLED_STATUS),
        ];

        foreach ([
            'tipo' => $tipo,
            'clase' => $clase,
            'categoria' => $categoria,
            'linea_tematica' => $lineaTematica,
        ] as $column => $value) {
            if ($value !== null && $value !== '') {
                $filters[] = new ReportFilter($column, '=', mb_strtoupper($value));
            }
        }

        return $filters;
    }

    /**
     * Date range on a column, either bound optional.
     *
     * @return array<int, ReportFilter>
     */
    protected function periodFilters(string $column, ?string $desde = null, ?string $hasta = null): array
    {
        $filters = [];

        if ($desde !== null && $desde !== '') {
            $filters[] = new ReportFilter($column, '>=', $desde);
        }

        if ($hasta !== null && $hasta !== '') {
            $filters[] = new ReportFilter($column, '<=', $hasta);
        }

        return $filters;
    }

    /**
     * What the caller asked for, echoed back so an answer can be quoted with its own scope.
     * A number without its filters is the thing that gets pasted into a slide and misread.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    protected function describeScope(array $extra = []): array
    {
        return array_filter(
            array_merge([
                'participacion' => implode(', ', IntrasGoalPolicy::PARTICIPATION_TYPES),
                'excluye' => 'versiones canceladas',
            ], $extra),
            fn ($value) => $value !== null && $value !== ''
        );
    }
}
