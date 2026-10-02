<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Reporting\Scoring;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Analytics\Reporting\DataTransferObject\AggregateRequest;
use Kanvas\Analytics\Reporting\DataTransferObject\ReportFilter;
use Kanvas\Analytics\Reporting\Services\ReportQueryService;
use Kanvas\Analytics\Reporting\Support\ReportRegistry;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Reporting\IntrasGoalPolicy;

/**
 * Turns an ejecutivo or empresa into the measurements the scorecards expect.
 *
 * Every figure comes from the flat tables, which is the reason they exist: each criterion is a
 * count or a distinct-year over `inscripcion` narrowed by inscription type, event type and event
 * class, and doing that per entity against the live schema would be the N+1 this layer was built
 * to remove.
 *
 * Loyalty — "# de años de inversión" — is distinct calendar years with qualifying activity, not
 * elapsed time. A company that ran events in 2019 and 2025 has two years of loyalty, not six.
 */
final class ScorecardMeasurementService
{
    /** Event classes the sheets call "internacionales". */
    private const array INTERNACIONALES = ['PROGRAMA INTERNACIONAL', 'CONFERENCIA INTERNACIONAL', 'SEMINARIO INTERNACIONAL'];

    /** In-house classes the sheets group together. */
    private const array IN_HOUSE_CLASSES = [
        'CONSULTORIA FORMATIVA',
        'OTRO SERVICIO',
        'PROYECTO DE ACOMPAÑAMIENTO',
        'PROYECTO DE CONSULTORIA',
        'SERVICIO DE CAPACITACION',
    ];

    public function __construct(
        private readonly AppInterface $app,
        private readonly Companies $company,
        private readonly ReportQueryService $service = new ReportQueryService(),
    ) {
    }

    /**
     * @return array<string, float|string|null>
     */
    public function forEjecutivo(int $peopleId): array
    {
        return [
            'eventos_internacionales' => $this->eventCount($peopleId, ['CONFIRMADO'], 'ABIERTO', self::INTERNACIONALES),
            'lealtad_internacionales' => $this->loyaltyYears($peopleId, ['CONFIRMADO'], 'ABIERTO', self::INTERNACIONALES),
            'eventos_evento_grande' => $this->eventCount($peopleId, ['CONFIRMADO', 'CONFIRMADO PLAN'], 'ABIERTO', ['EVENTO GRANDE']),
            'lealtad_evento_grande' => $this->loyaltyYears($peopleId, ['CONFIRMADO', 'CONFIRMADO PLAN'], 'ABIERTO', ['EVENTO GRANDE']),
            'eventos_seminario' => $this->eventCount($peopleId, ['CONFIRMADO', 'CONFIRMADO PLAN', 'PROGRAMA'], 'ABIERTO', ['SEMINARIO']),
            'lealtad_seminario' => $this->loyaltyYears($peopleId, ['CONFIRMADO', 'CONFIRMADO PLAN', 'PROGRAMA'], 'ABIERTO', ['SEMINARIO']),
            'eventos_in_house' => $this->eventCount($peopleId, ['CONFIRMADO'], 'IN-HOUSE', self::IN_HOUSE_CLASSES),
        ];
    }

    /**
     * @param array<string, string|null> $companyLetters clasificacion / potencialidad already computed
     *
     * @return array<string, float|string|null>
     */
    public function forEjecutivoPotential(int $peopleId, array $companyLetters = []): array
    {
        $person = $this->firstRow('ejecutivo', 'peoples_id', $peopleId);

        return [
            'nivel' => $this->meaningful($person['nivel'] ?? null),
            'persona_clave' => ((int) ($person['persona_clave'] ?? 0)) === 1 ? 'SI' : 'NO',
            'clasificacion_empresa' => $companyLetters['clasificacion'] ?? null,
            'potencialidad_empresa' => $companyLetters['potencialidad'] ?? null,
        ];
    }

    /**
     * @return array<string, float|string|null>
     */
    public function forEmpresa(int $organizationId): array
    {
        $quotes = $this->approvedQuotes($organizationId);

        return [
            'participantes_internacionales' => $this->participantCount($organizationId, ['CONFIRMADO'], 'ABIERTO', self::INTERNACIONALES),
            'lealtad_internacionales' => $this->companyLoyaltyYears($organizationId, ['CONFIRMADO'], 'ABIERTO', self::INTERNACIONALES),
            'participantes_evento_grande' => $this->participantCount($organizationId, ['CONFIRMADO', 'CONFIRMADO PLAN'], 'ABIERTO', ['EVENTO GRANDE']),
            'lealtad_evento_grande' => $this->companyLoyaltyYears($organizationId, ['CONFIRMADO', 'CONFIRMADO PLAN'], 'ABIERTO', ['EVENTO GRANDE']),
            'participantes_seminario' => $this->participantCount($organizationId, ['CONFIRMADO', 'CONFIRMADO PLAN', 'PROGRAMA'], 'ABIERTO', ['SEMINARIO']),
            'lealtad_seminario' => $this->companyLoyaltyYears($organizationId, ['CONFIRMADO', 'CONFIRMADO PLAN', 'PROGRAMA'], 'ABIERTO', ['SEMINARIO']),
            'eventos_outsourcing' => $this->participantCount($organizationId, [], 'IN-HOUSE', ['OUTSOURCING DE EVENTOS']),

            'cliente_axis' => $this->hasPlan($organizationId) ? 'SI' : 'NO',
            'propuestas_in_house_aprobadas' => $quotes['cantidad'],
            'inversion_propuestas_in_house' => $quotes['monto'],
            'lealtad_in_house' => $quotes['anios'],
        ];
    }

    /**
     * @return array<string, float|string|null>
     */
    public function forEmpresaPotential(int $organizationId): array
    {
        $empresa = $this->firstRow('empresa', 'organizations_id', $organizationId);

        return [
            'tamano' => $this->meaningful($empresa['tamano'] ?? null),
            'penetracion_sector' => $this->share('sector', $this->meaningful($empresa['sector'] ?? null)),
            'penetracion_tipo' => $this->share('tipo', $this->meaningful($empresa['tipo'] ?? null)),
            'penetracion_ciudad' => $this->share('ciudad', $this->meaningful($empresa['ciudad'] ?? null)),
        ];
    }

    /**
     * EVENTOS — CLASIFICACIÓN INTERNA, over the event's past, non-cancelled versions.
     *
     * Participants are averaged per version, not pooled: the sheet asks how many people a run of
     * the event draws. A version nobody participated in has no row and is left out of the average
     * rather than pulling it to zero.
     *
     * Satisfaction reads the per-version `satisfaccion_participantes` SIPGO already rolls up,
     * rather than re-averaging raw answers matched by question text. Both figures are rounded to
     * the sheet's own precision so a band boundary (">25", "4.9-5") reads the way it is written.
     *
     * @return array<string, float|null>
     */
    public function forEvento(int $eventoId): array
    {
        $today = Carbon::now()->toDateString();

        $perVersion = $this->service->aggregate(
            new ReportRegistry()->find($this->app, 'inscripcion'),
            $this->app,
            $this->company,
            AggregateRequest::fromInput(
                aggregates: [['function' => 'COUNT_DISTINCT', 'column' => 'peoples_id', 'alias' => 'participantes']],
                groupBy: ['version_id'],
                limit: ReportQueryService::MAX_LIMIT
            ),
            [
                new ReportFilter('evento_id', '=', $eventoId),
                new ReportFilter('tipo_inscripcion', 'IN', IntrasGoalPolicy::PARTICIPATION_TYPES),
                new ReportFilter('estatus_version', '!=', IntrasGoalPolicy::CANCELLED_STATUS),
                new ReportFilter('fecha_inicio', '<', $today),
            ]
        );

        $satisfaction = $this->scalarOrNull(
            new ReportRegistry()->find($this->app, 'evento_version'),
            [
                new ReportFilter('evento_id', '=', $eventoId),
                new ReportFilter('estatus', '!=', IntrasGoalPolicy::CANCELLED_STATUS),
                new ReportFilter('fecha_inicio', '<', $today),
            ],
            'AVG',
            'satisfaccion_participantes'
        );

        return [
            'promedio_participantes' => $perVersion === []
                ? null
                : round(array_sum(array_column($perVersion, 'participantes')) / count($perVersion), 1),
            'satisfaccion' => $satisfaction === null ? null : round($satisfaction, 2),
        ];
    }

    /**
     * What share of INTRAS's executive base sits in this company's sector / type / city.
     *
     * **This is a share of the base, not a conversion rate**, and the distinction decides whether
     * the criterion carries any information at all. Read as "of the executives we know in this
     * sector, how many have attended something", every cohort lands between 52% and 78% against
     * a rubric whose top band starts at 35% — so all three criteria max out and every company
     * scores A. Read as a share, the same data spreads across the bands: FINANCIERO 24.2%,
     * CONSUMO MASIVO 11.0%, and a long tail below 1%.
     *
     * The band shape is what settles it. `35 / 25 / 15 / 1` is not a rubric anyone writes for a
     * quantity that never falls below 52%, and "% de ejecutivos del sector" reads naturally as
     * the sector's share of the executives. The conversion rate is also unknowable as intended:
     * true market penetration needs every executive in the sector, and SIPGO only holds the ones
     * INTRAS has already met.
     *
     * The cohort is defined on `empresa` and counted on `ejecutivo`, so this joins rather than
     * filtering one table — and it cannot resolve the cohort to a list of ids first, because
     * SANTO DOMINGO alone is 3,479 companies against a 1,000-row query cap. Both tables carry
     * `companies_id` and both are constrained, so the join cannot reach across tenants.
     */
    private function share(string $column, ?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $connection = DB::connection('reporting');
        $ejecutivos = sprintf('rpt_ejecutivo_app%d', $this->app->getId());
        $empresas = sprintf('rpt_empresa_app%d', $this->app->getId());
        $schema = $connection->getSchemaBuilder();

        if (! $schema->hasTable($ejecutivos) || ! $schema->hasTable($empresas)) {
            return null;
        }

        // The denominator is everyone whose company declares this attribute at all — including
        // the rows that declare it would make the shares sum to something other than 100%.
        $row = $connection->table($ejecutivos . ' as e')
            ->join($empresas . ' as emp', function ($join): void {
                $join->on('emp.organizations_id', '=', 'e.empresa_id')
                    ->on('emp.companies_id', '=', 'e.companies_id');
            })
            ->where('e.companies_id', $this->company->getId())
            ->whereNotNull('emp.' . $column)
            ->where('emp.' . $column, '!=', '')
            ->selectRaw('COUNT(*) AS total, SUM(emp.' . $column . ' = ?) AS cohorte', [$value])
            ->first();

        $total = (int) ($row->total ?? 0);

        // Nothing to take a share of is unknown, not 0% — a zero would band as the bottom rung
        // and read as a measured failure rather than an absence of data.
        if ($total <= 0) {
            return null;
        }

        return round(((int) ($row->cohorte ?? 0) / $total) * 100, 2);
    }

    /**
     * @param list<string> $inscriptionTypes
     * @param list<string> $classes
     */
    private function eventCount(
        int $peopleId,
        array $inscriptionTypes,
        string $tipo,
        array $classes
    ): float {
        return $this->scalar(
            new ReportRegistry()->find($this->app, 'inscripcion'),
            $this->inscriptionFilters($inscriptionTypes, $tipo, $classes, 'peoples_id', $peopleId),
            'COUNT_DISTINCT',
            'version_id'
        );
    }

    /**
     * @param list<string> $inscriptionTypes
     * @param list<string> $classes
     */
    private function participantCount(
        int $organizationId,
        array $inscriptionTypes,
        string $tipo,
        array $classes
    ): float {
        return $this->scalar(
            new ReportRegistry()->find($this->app, 'inscripcion'),
            $this->inscriptionFilters($inscriptionTypes, $tipo, $classes, 'empresa_id', $organizationId),
            'COUNT_DISTINCT',
            'peoples_id'
        );
    }

    /**
     * @param list<string> $inscriptionTypes
     * @param list<string> $classes
     */
    private function loyaltyYears(
        int $peopleId,
        array $inscriptionTypes,
        string $tipo,
        array $classes
    ): float {
        return $this->distinctYears($this->inscriptionFilters($inscriptionTypes, $tipo, $classes, 'peoples_id', $peopleId));
    }

    /**
     * @param list<string> $inscriptionTypes
     * @param list<string> $classes
     */
    private function companyLoyaltyYears(
        int $organizationId,
        array $inscriptionTypes,
        string $tipo,
        array $classes
    ): float {
        return $this->distinctYears($this->inscriptionFilters($inscriptionTypes, $tipo, $classes, 'empresa_id', $organizationId));
    }

    /**
     * @param array<int, ReportFilter> $filters
     */
    private function distinctYears(array $filters): float
    {
        $rows = $this->service->aggregate(
            new ReportRegistry()->find($this->app, 'inscripcion'),
            $this->app,
            $this->company,
            AggregateRequest::fromInput(
                aggregates: [['function' => 'COUNT', 'alias' => 'n']],
                groupBy: ['fecha_inicio:year'],
                limit: ReportQueryService::MAX_LIMIT
            ),
            [...$filters, new ReportFilter('fecha_inicio', 'IS NOT NULL')]
        );

        return (float) count($rows);
    }

    /**
     * @param list<string> $inscriptionTypes
     * @param list<string> $classes
     *
     * @return array<int, ReportFilter>
     */
    private function inscriptionFilters(
        array $inscriptionTypes,
        string $tipo,
        array $classes,
        string $keyColumn,
        int $keyValue
    ): array {
        $filters = [
            new ReportFilter($keyColumn, '=', $keyValue),
            new ReportFilter('tipo', '=', $tipo),
            new ReportFilter('clase', 'IN', $classes),
            new ReportFilter('estatus_version', '!=', IntrasGoalPolicy::CANCELLED_STATUS),
        ];

        if ($inscriptionTypes !== []) {
            $filters[] = new ReportFilter('tipo_inscripcion', 'IN', $inscriptionTypes);
        }

        return $filters;
    }

    /**
     * @return array{cantidad: float, monto: float, anios: float}
     */
    private function approvedQuotes(int $organizationId): array
    {
        $rows = $this->service->aggregate(
            new ReportRegistry()->find($this->app, 'cotizacion'),
            $this->app,
            $this->company,
            AggregateRequest::fromInput(
                aggregates: [
                    ['function' => 'COUNT', 'alias' => 'n'],
                    ['function' => 'SUM', 'column' => 'monto', 'alias' => 'monto'],
                ],
                groupBy: ['fecha_solicitud:year'],
                limit: ReportQueryService::MAX_LIMIT
            ),
            [
                new ReportFilter('empresa_id', '=', $organizationId),
                new ReportFilter('es_aprobada', '=', 1),
            ]
        );

        $count = 0.0;
        $amount = 0.0;
        $years = 0;

        foreach ($rows as $row) {
            $count += (float) $row['n'];
            $amount += (float) ($row['monto'] ?? 0);

            // An approved quote with no request date still counts and sums; it just proves no year.
            if ($row['fecha_solicitud_year'] !== null) {
                $years++;
            }
        }

        return ['cantidad' => $count, 'monto' => $amount, 'anios' => (float) $years];
    }

    private function hasPlan(int $organizationId): bool
    {
        return $this->scalar(
            new ReportRegistry()->find($this->app, 'empresa_plan'),
            [new ReportFilter('organizations_id', '=', $organizationId)],
            'COUNT',
            null
        ) > 0;
    }

    /**
     * @param array<int, ReportFilter> $filters
     */
    private function scalar(
        object $definition,
        array $filters,
        string $function,
        ?string $column
    ): float {
        return $this->scalarOrNull(
            $definition,
            $filters,
            $function,
            $column
        ) ?? 0.0;
    }

    /**
     * `scalar()` without the zero default: an AVG over no rows is "not measured", which a criterion
     * must skip rather than score as the bottom band.
     *
     * @param array<int, ReportFilter> $filters
     */
    private function scalarOrNull(
        object $definition,
        array $filters,
        string $function,
        ?string $column
    ): ?float {
        $rows = $this->service->aggregate(
            $definition,
            $this->app,
            $this->company,
            AggregateRequest::fromInput(
                aggregates: [['function' => $function, 'column' => $column, 'alias' => 'valor']],
                limit: 1
            ),
            $filters
        );

        $value = $rows[0]['valor'] ?? null;

        return $value === null ? null : (float) $value;
    }

    /**
     * SIPGO's "not specified" placeholder, turned back into the absence it represents.
     *
     * `Pendiente` is a real stored value across 17 columns and is deliberately kept verbatim in
     * the flat tables — they mirror the source. But it is not a measurement, and feeding it to a
     * criterion scores it as the bottom band: 1,534 ejecutivos were rated as if they held the
     * lowest `nivel` when the truth is that nobody had set one. Null is what the scorecard reads
     * as "could not measure".
     */
    private function meaningful(?string $value): ?string
    {
        $value = Str::trimToNull((string) ($value ?? ''));

        return $value !== null && mb_strtoupper($value) === 'PENDIENTE' ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function firstRow(string $model, string $keyColumn, int $keyValue): array
    {
        $rows = $this->service->search(
            new ReportRegistry()->find($this->app, $model),
            $this->app,
            $this->company,
            [new ReportFilter($keyColumn, '=', $keyValue)],
            limit: 1
        );

        return $rows[0] ?? [];
    }
}
