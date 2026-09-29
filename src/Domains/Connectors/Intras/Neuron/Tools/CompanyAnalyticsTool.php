<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Neuron\Tools;

use Kanvas\Analytics\Reporting\DataTransferObject\AggregateRequest;
use Kanvas\Analytics\Reporting\DataTransferObject\ReportFilter;
use Kanvas\Analytics\Reporting\Services\ReportQueryService;
use Kanvas\Analytics\Reporting\Support\ReportRegistry;
use Kanvas\Connectors\Intras\Reporting\Concerns\BuildsIntrasFilters;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Company-level analytics: who the top clients are, who has gone quiet, and who is trending down.
 *
 * Four of the modes need something `run_report` cannot express — a ranking, an anti-join, a
 * two-period comparison, or a cross-table filter — which is why they are one parameterised tool
 * rather than five filter recipes the agent has to assemble correctly.
 */
#[AgentTool(name: 'Intras Company Analytics', category: 'reporting')]
class CompanyAnalyticsTool extends Tool implements HasRunKey
{
    use TrackByInputs;
    use BuildsIntrasFilters;
    use HasKanvasContext;

    private const array MODES = ['top', 'inactivas', 'tendencia', 'por_potencialidad'];

    public function __construct()
    {
        parent::__construct(
            name: 'intras_company_analytics',
            description: 'Analítica por empresa. mode: "top" (ranking por participantes), '
                . '"inactivas" (empresas top sin eventos en el periodo, o sin eventos in-house), '
                . '"tendencia" (compara participación entre dos periodos para detectar caídas), '
                . '"por_potencialidad" (empresas por clasificación/potencialidad, p. ej. las de '
                . 'potencialidad A que nunca han hecho eventos). Filtra por tamaño, sector y '
                . 'línea temática.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'mode', type: PropertyType::STRING, description: 'top | inactivas | tendencia | por_potencialidad.', required: false),
            new ToolProperty(name: 'tipo', type: PropertyType::STRING, description: 'ABIERTO o IN-HOUSE.', required: false),
            new ToolProperty(name: 'clase', type: PropertyType::STRING, description: 'Clase de evento.', required: false),
            new ToolProperty(name: 'linea_tematica', type: PropertyType::STRING, description: 'Línea temática.', required: false),
            new ToolProperty(name: 'tamano', type: PropertyType::STRING, description: 'Grande | Mediana | Pequeña. Sólo en por_potencialidad y top.', required: false),
            new ToolProperty(name: 'sector', type: PropertyType::STRING, description: 'Sector empresarial.', required: false),
            new ToolProperty(name: 'clasificacion', type: PropertyType::STRING, description: 'A-E, clasificación interna. Sólo en por_potencialidad.', required: false),
            new ToolProperty(name: 'potencialidad', type: PropertyType::STRING, description: 'A-E, potencialidad externa. Sólo en por_potencialidad.', required: false),
            new ToolProperty(name: 'sin_eventos', type: PropertyType::BOOLEAN, description: 'En por_potencialidad: sólo empresas sin inscripciones.', required: false),
            new ToolProperty(name: 'desde', type: PropertyType::STRING, description: 'Periodo actual desde, YYYY-MM-DD.', required: false),
            new ToolProperty(name: 'hasta', type: PropertyType::STRING, description: 'Periodo actual hasta, YYYY-MM-DD.', required: false),
            new ToolProperty(name: 'comparar_desde', type: PropertyType::STRING, description: 'Periodo anterior desde. Sólo en tendencia.', required: false),
            new ToolProperty(name: 'comparar_hasta', type: PropertyType::STRING, description: 'Periodo anterior hasta. Sólo en tendencia.', required: false),
            new ToolProperty(name: 'limit', type: PropertyType::INTEGER, description: 'Máximo de empresas. Por defecto 25.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $mode = null,
        ?string $tipo = null,
        ?string $clase = null,
        ?string $linea_tematica = null,
        ?string $tamano = null,
        ?string $sector = null,
        ?string $clasificacion = null,
        ?string $potencialidad = null,
        ?bool $sin_eventos = null,
        ?string $desde = null,
        ?string $hasta = null,
        ?string $comparar_desde = null,
        ?string $comparar_hasta = null,
        ?int $limit = null
    ): array {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('company analytics');
        }

        $mode = mb_strtolower($mode ?? 'top');

        if (! in_array($mode, self::MODES, true)) {
            return ['error' => 'mode debe ser uno de: ' . implode(', ', self::MODES) . '.'];
        }

        try {
            return match ($mode) {
                'por_potencialidad' => $this->byPotential($clasificacion, $potencialidad, $tamano, $sector, $sin_eventos === true, $limit ?? 25),
                'tendencia' => $this->trend($tipo, $clase, $desde, $hasta, $comparar_desde, $comparar_hasta, $limit ?? 25),
                'inactivas' => $this->inactive($tipo, $clase, $desde, $hasta, $limit ?? 25),
                default => $this->top($tipo, $clase, $linea_tematica, $tamano, $sector, $desde, $hasta, $limit ?? 25),
            };
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Ranking by distinct participating ejecutivos.
     *
     * `tamano` and `sector` live on `empresa`, not `inscripcion`, so when either is supplied the
     * candidate companies are resolved there first and applied as an id filter — the join the
     * query service deliberately does not do.
     *
     * @return array<string, mixed>
     */
    private function top(
        ?string $tipo,
        ?string $clase,
        ?string $lineaTematica,
        ?string $tamano,
        ?string $sector,
        ?string $desde,
        ?string $hasta,
        int $limit
    ): array {
        $service = new ReportQueryService();
        $definition = new ReportRegistry()->find($this->app, 'inscripcion');

        $filters = array_merge(
            $this->participationFilters($tipo, $clase, null, $lineaTematica),
            $this->periodFilters('fecha_inicio', $desde, $hasta),
        );

        $restricted = $this->companyIdsMatching($tamano, $sector);

        if ($restricted !== null) {
            if ($restricted === []) {
                return ['empresas' => [], 'scope' => ['nota' => 'ninguna empresa coincide con tamaño/sector']];
            }

            $filters[] = new ReportFilter('empresa_id', 'IN', $restricted);
        }

        return [
            'empresas' => $service->aggregate(
                $definition,
                $this->app,
                $this->company,
                AggregateRequest::fromInput(
                    aggregates: [
                        ['function' => 'COUNT_DISTINCT', 'column' => 'peoples_id', 'alias' => 'ejecutivos'],
                        ['function' => 'COUNT', 'alias' => 'inscripciones'],
                    ],
                    groupBy: ['empresa_id', 'empresa'],
                    orderBy: 'ejecutivos',
                    limit: $limit
                ),
                $filters
            ),
            'scope' => $this->describeScope([
                'tipo' => $tipo,
                'clase' => $clase,
                'linea_tematica' => $lineaTematica,
                'tamano' => $tamano,
                'sector' => $sector,
                'desde' => $desde,
                'hasta' => $hasta,
            ]),
        ];
    }

    /**
     * Companies with history but nothing in the window — the anti-join.
     *
     * @return array<string, mixed>
     */
    private function inactive(
        ?string $tipo,
        ?string $clase,
        ?string $desde,
        ?string $hasta,
        int $limit
    ): array {
        $service = new ReportQueryService();
        $definition = new ReportRegistry()->find($this->app, 'inscripcion');

        $active = $service->aggregate(
            $definition,
            $this->app,
            $this->company,
            AggregateRequest::fromInput(
                aggregates: [['function' => 'COUNT', 'alias' => 'inscripciones']],
                groupBy: ['empresa_id'],
                limit: ReportQueryService::MAX_LIMIT
            ),
            array_merge(
                $this->participationFilters($tipo, $clase),
                $this->periodFilters('fecha_inicio', $desde, $hasta),
            )
        );

        $activeIds = array_map(fn (array $row) => (int) $row['empresa_id'], $active);

        $everything = $service->aggregate(
            $definition,
            $this->app,
            $this->company,
            AggregateRequest::fromInput(
                aggregates: [
                    ['function' => 'COUNT_DISTINCT', 'column' => 'peoples_id', 'alias' => 'ejecutivos_historico'],
                    ['function' => 'MAX', 'column' => 'fecha_inicio', 'alias' => 'ultima_participacion'],
                ],
                groupBy: ['empresa_id', 'empresa'],
                orderBy: 'ejecutivos_historico',
                limit: ReportQueryService::MAX_LIMIT
            ),
            $this->participationFilters($tipo, $clase)
        );

        $inactive = array_values(array_filter(
            $everything,
            fn (array $row) => ! in_array((int) $row['empresa_id'], $activeIds, true)
        ));

        return [
            'empresas_inactivas' => array_slice($inactive, 0, $limit),
            'total_inactivas' => count($inactive),
            'total_activas_en_periodo' => count($activeIds),
            'scope' => $this->describeScope(['tipo' => $tipo, 'clase' => $clase, 'desde' => $desde, 'hasta' => $hasta]),
        ];
    }

    /**
     * Two windows, compared — "qué empresa tiene tendencia a disminuir su participación".
     *
     * @return array<string, mixed>
     */
    private function trend(
        ?string $tipo,
        ?string $clase,
        ?string $desde,
        ?string $hasta,
        ?string $compararDesde,
        ?string $compararHasta,
        int $limit
    ): array {
        if ($desde === null || $compararDesde === null) {
            return ['error' => 'tendencia necesita desde/hasta y comparar_desde/comparar_hasta.'];
        }

        $current = $this->participationByCompany($tipo, $clase, $desde, $hasta);
        $previous = $this->participationByCompany($tipo, $clase, $compararDesde, $compararHasta);

        $rows = [];

        foreach ($previous as $companyId => $before) {
            $now = $current[$companyId]['ejecutivos'] ?? 0;
            $rows[] = [
                'empresa_id' => $companyId,
                'empresa' => $before['empresa'],
                'periodo_anterior' => $before['ejecutivos'],
                'periodo_actual' => $now,
                'variacion' => $now - $before['ejecutivos'],
                'variacion_pct' => $before['ejecutivos'] > 0
                    ? round((($now - $before['ejecutivos']) / $before['ejecutivos']) * 100, 1)
                    : null,
            ];
        }

        usort($rows, fn (array $a, array $b) => $a['variacion'] <=> $b['variacion']);

        return [
            'empresas' => array_slice($rows, 0, $limit),
            'nota' => 'ordenado de mayor caída a mayor crecimiento; sólo empresas con actividad en el periodo anterior',
            'scope' => $this->describeScope([
                'tipo' => $tipo,
                'clase' => $clase,
                'periodo_actual' => trim(($desde ?? '') . ' .. ' . ($hasta ?? '')),
                'periodo_anterior' => trim(($compararDesde ?? '') . ' .. ' . ($compararHasta ?? '')),
            ]),
        ];
    }

    /**
     * @return array<int, array{empresa: string, ejecutivos: int}>
     */
    private function participationByCompany(
        ?string $tipo,
        ?string $clase,
        ?string $desde,
        ?string $hasta
    ): array {
        $rows = new ReportQueryService()->aggregate(
            new ReportRegistry()->find($this->app, 'inscripcion'),
            $this->app,
            $this->company,
            AggregateRequest::fromInput(
                aggregates: [['function' => 'COUNT_DISTINCT', 'column' => 'peoples_id', 'alias' => 'ejecutivos']],
                groupBy: ['empresa_id', 'empresa'],
                limit: ReportQueryService::MAX_LIMIT
            ),
            array_merge(
                $this->participationFilters($tipo, $clase),
                $this->periodFilters('fecha_inicio', $desde, $hasta),
            )
        );

        $byCompany = [];

        foreach ($rows as $row) {
            $byCompany[(int) $row['empresa_id']] = [
                'empresa' => (string) $row['empresa'],
                'ejecutivos' => (int) $row['ejecutivos'],
            ];
        }

        return $byCompany;
    }

    /**
     * Straight off `empresa` — clasificación, potencialidad, tamaño, sector, and optionally only
     * those with no participation at all.
     *
     * @return array<string, mixed>
     */
    private function byPotential(
        ?string $clasificacion,
        ?string $potencialidad,
        ?string $tamano,
        ?string $sector,
        bool $sinEventos,
        int $limit
    ): array {
        $service = new ReportQueryService();
        $definition = new ReportRegistry()->find($this->app, 'empresa');

        $filters = [];

        foreach ([
            'clasificacion' => $clasificacion,
            'potencialidad' => $potencialidad,
            'tamano' => $tamano,
            'sector' => $sector,
        ] as $column => $value) {
            if ($value !== null && $value !== '') {
                $filters[] = new ReportFilter($column, '=', $value);
            }
        }

        if ($sinEventos) {
            $filters[] = new ReportFilter('total_inscripciones', '=', 0);
        }

        $totals = $service->aggregate(
            $definition,
            $this->app,
            $this->company,
            AggregateRequest::fromInput(aggregates: [['function' => 'COUNT', 'alias' => 'total']], limit: 1),
            $filters
        );

        return [
            'total' => (int) ($totals[0]['total'] ?? 0),
            'empresas' => $service->search(
                $definition,
                $this->app,
                $this->company,
                $filters,
                select: ['organizations_id', 'nombre', 'sector', 'tamano', 'clasificacion', 'potencialidad', 'total_ejecutivos', 'total_inscripciones', 'ultima_inscripcion'],
                limit: $limit
            ),
            'scope' => array_filter([
                'clasificacion' => $clasificacion,
                'potencialidad' => $potencialidad,
                'tamano' => $tamano,
                'sector' => $sector,
                'sin_eventos' => $sinEventos ? 'sí' : null,
            ], fn ($v) => $v !== null && $v !== ''),
        ];
    }

    /**
     * Company ids matching attributes that live on `empresa` rather than `inscripcion`.
     *
     * @return array<int, int>|null null when no attribute filter was asked for
     */
    private function companyIdsMatching(?string $tamano, ?string $sector): ?array
    {
        if (($tamano === null || $tamano === '') && ($sector === null || $sector === '')) {
            return null;
        }

        $filters = [];

        foreach (['tamano' => $tamano, 'sector' => $sector] as $column => $value) {
            if ($value !== null && $value !== '') {
                $filters[] = new ReportFilter($column, '=', $value);
            }
        }

        $rows = new ReportQueryService()->search(
            new ReportRegistry()->find($this->app, 'empresa'),
            $this->app,
            $this->company,
            $filters,
            select: ['organizations_id'],
            limit: ReportQueryService::MAX_LIMIT
        );

        return array_map(fn (array $row) => (int) $row['organizations_id'], $rows);
    }
}
