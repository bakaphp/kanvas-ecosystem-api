<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Neuron\Tools;

use Kanvas\Analytics\Reporting\DataTransferObject\AggregateRequest;
use Kanvas\Analytics\Reporting\DataTransferObject\ReportFilter;
use Kanvas\Analytics\Reporting\Services\ReportQueryService;
use Kanvas\Analytics\Reporting\Support\ReportRegistry;
use Kanvas\Connectors\Intras\Reporting\Concerns\BuildsIntrasFilters;
use Kanvas\Connectors\Intras\Reporting\IntrasGoalPolicy;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Rank event versions, or average across them.
 *
 * "Los 5 eventos con más participantes", "los 5 con mayor satisfacción", "el promedio de
 * participantes por evento" and "qué eventos hicimos, con cuántos participantes y cómo quedó su
 * evaluación" are one shape with different metrics — and all four need an ORDER BY or an AVG,
 * which is exactly what the generic report tool cannot do.
 *
 * Cancelled versions are excluded from every mode: a cancelled event has no participants to
 * rank and no satisfaction to average.
 */
#[AgentTool(name: 'Intras Event Ranking', category: 'reporting')]
class EventRankingTool extends Tool
{
    use TrackByInputs;
    use BuildsIntrasFilters;
    use HasKanvasContext;

    protected string $name = 'intras_event_ranking';

    protected ?string $description = 'Ranking de versiones de evento por métrica: "participantes", '
        . '"asistentes", "satisfaccion" o "empresas". Devuelve el top N con sus '
        . 'inscripciones y satisfacción. Con promedio=true devuelve el promedio de la '
        . 'métrica en vez del ranking. Excluye versiones canceladas.';

    /**
     * Metrics that live on the version row.
     *
     * `satisfaccion` is deliberately absent: `evento_version.satisfaccion_participantes` is 1.0
     * on every row in SIPGO itself — min and max both 1.0 across all 1,587 non-zero values — so
     * it is a flag, not a score. Real satisfaction is the average of the numeric evaluation
     * answers, which is a different grain and takes the branch below.
     */
    private const array VERSION_METRICS = [
        'participantes' => 'inscripciones',
        'asistentes' => 'asistentes',
        'empresas' => 'empresas',
    ];

    /**
     * Below this many answers an average is noise — one perfect response would otherwise top
     * the ranking ahead of an event with a hundred.
     */
    private const int DEFAULT_MIN_RESPONSES = 20;

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'metric', type: PropertyType::STRING, description: 'participantes | asistentes | empresas | satisfaccion. Por defecto participantes.', required: false),
            new ToolProperty(name: 'min_respuestas', type: PropertyType::INTEGER, description: 'Sólo para metric=satisfaccion: mínimo de respuestas para que una versión entre al ranking. Por defecto 20.', required: false),
            new ToolProperty(name: 'promedio', type: PropertyType::BOOLEAN, description: 'true para el promedio en vez del ranking.', required: false),
            new ToolProperty(name: 'top_n', type: PropertyType::INTEGER, description: 'Cuántos devolver. Por defecto 5.', required: false),
            new ToolProperty(name: 'tipo', type: PropertyType::STRING, description: 'ABIERTO o IN-HOUSE.', required: false),
            new ToolProperty(name: 'clase', type: PropertyType::STRING, description: 'Clase de evento.', required: false),
            new ToolProperty(name: 'categoria', type: PropertyType::STRING, description: 'Categoría de evento.', required: false),
            new ToolProperty(name: 'desde', type: PropertyType::STRING, description: 'Fecha inicial YYYY-MM-DD.', required: false),
            new ToolProperty(name: 'hasta', type: PropertyType::STRING, description: 'Fecha final YYYY-MM-DD.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $metric = null,
        ?bool $promedio = null,
        ?int $top_n = null,
        ?string $tipo = null,
        ?string $clase = null,
        ?string $categoria = null,
        ?string $desde = null,
        ?string $hasta = null,
        ?int $min_respuestas = null
    ): array {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('event ranking');
        }

        $metric = mb_strtolower($metric ?? 'participantes');

        if ($metric === 'satisfaccion') {
            return $this->bySatisfaction($top_n ?? 5, $min_respuestas ?? self::DEFAULT_MIN_RESPONSES, $tipo, $clase, $desde, $hasta, $promedio === true);
        }

        if (! array_key_exists($metric, self::VERSION_METRICS)) {
            return ['error' => 'metric debe ser uno de: ' . implode(', ', [...array_keys(self::VERSION_METRICS), 'satisfaccion']) . '.'];
        }

        $column = self::VERSION_METRICS[$metric];

        try {
            $definition = new ReportRegistry()->find($this->app, 'evento_version');
            $service = new ReportQueryService();

            $filters = $this->periodFilters('fecha_inicio', $desde, $hasta);
            $filters[] = new ReportFilter('estatus', '!=', IntrasGoalPolicy::CANCELLED_STATUS);

            foreach (['tipo' => $tipo, 'clase' => $clase, 'categoria' => $categoria] as $col => $value) {
                if ($value !== null && $value !== '') {
                    $filters[] = new ReportFilter($col, '=', mb_strtoupper($value));
                }
            }

            $scope = array_filter([
                'metric' => $metric,
                'tipo' => $tipo,
                'clase' => $clase,
                'categoria' => $categoria,
                'desde' => $desde,
                'hasta' => $hasta,
                'excluye' => 'versiones canceladas',
            ], fn ($v) => $v !== null && $v !== '');

            if ($promedio === true) {
                $rows = $service->aggregate(
                    $definition,
                    $this->app,
                    $this->company,
                    AggregateRequest::fromInput(
                        aggregates: [
                            ['function' => 'AVG', 'column' => $column, 'alias' => 'promedio'],
                            ['function' => 'COUNT', 'alias' => 'versiones'],
                        ],
                        limit: 1
                    ),
                    $filters
                );

                return [
                    'promedio' => round((float) ($rows[0]['promedio'] ?? 0), 2),
                    'versiones' => (int) ($rows[0]['versiones'] ?? 0),
                    'scope' => $scope,
                ];
            }

            return [
                'top' => $service->aggregate(
                    $definition,
                    $this->app,
                    $this->company,
                    AggregateRequest::fromInput(
                        aggregates: [
                            ['function' => 'MAX', 'column' => $column, 'alias' => 'valor'],
                            ['function' => 'MAX', 'column' => 'inscripciones', 'alias' => 'inscripciones'],
                            ['function' => 'MAX', 'column' => 'fecha_inicio', 'alias' => 'fecha'],
                        ],
                        groupBy: ['version_id', 'evento', 'version'],
                        orderBy: 'valor',
                        limit: $top_n ?? 5
                    ),
                    $filters
                ),
                'scope' => $scope,
            ];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Satisfaction is the average of the numeric evaluation answers, not a column on the version.
     *
     * `evento_version.satisfaccion_participantes` is 1.0 on every row in SIPGO, so ranking by it
     * puts events with zero participants at the top. The answers carry a real 0-5 scale — 134,268
     * scored answers across 765 versions in agency 1, averaging 4.41.
     *
     * Answers flagged "excluir de reportes" are left out, which is what that flag is for and what
     * the legacy report never honoured.
     *
     * @return array<string, mixed>
     */
    private function bySatisfaction(
        int $topN,
        int $minResponses,
        ?string $tipo,
        ?string $clase,
        ?string $desde,
        ?string $hasta,
        bool $average
    ): array {
        $definition = new ReportRegistry()->find($this->app, 'evaluacion');
        $service = new ReportQueryService();

        $filters = $this->periodFilters('fecha_evento', $desde, $hasta);
        $filters[] = new ReportFilter('es_numerica', '=', 1);
        $filters[] = new ReportFilter('excluir_de_reportes', '=', 0);
        $filters[] = new ReportFilter('tipo_pregunta', '=', 'Satisfacción');

        foreach (['tipo' => $tipo, 'clase' => $clase] as $column => $value) {
            if ($value !== null && $value !== '') {
                $filters[] = new ReportFilter($column, '=', mb_strtoupper($value));
            }
        }

        $scope = array_filter([
            'metric' => 'satisfaccion',
            'escala' => '0-5, promedio de respuestas numéricas de satisfacción',
            'excluye' => 'respuestas marcadas excluir de reportes',
            'min_respuestas' => $average ? null : $minResponses,
            'tipo' => $tipo,
            'clase' => $clase,
            'desde' => $desde,
            'hasta' => $hasta,
        ], fn ($v) => $v !== null && $v !== '');

        if ($average) {
            $rows = $service->aggregate(
                $definition,
                $this->app,
                $this->company,
                AggregateRequest::fromInput(
                    aggregates: [
                        ['function' => 'AVG', 'column' => 'puntuacion', 'alias' => 'promedio'],
                        ['function' => 'COUNT', 'alias' => 'respuestas'],
                        ['function' => 'COUNT_DISTINCT', 'column' => 'version_id', 'alias' => 'versiones'],
                    ],
                    limit: 1
                ),
                $filters
            );

            return [
                'promedio' => round((float) ($rows[0]['promedio'] ?? 0), 2),
                'respuestas' => (int) ($rows[0]['respuestas'] ?? 0),
                'versiones' => (int) ($rows[0]['versiones'] ?? 0),
                'scope' => $scope,
            ];
        }

        // Ranked wide, then filtered on the response floor here — the aggregate surface has no
        // HAVING, and the candidate set is versions, not answers.
        $ranked = $service->aggregate(
            $definition,
            $this->app,
            $this->company,
            AggregateRequest::fromInput(
                aggregates: [
                    ['function' => 'AVG', 'column' => 'puntuacion', 'alias' => 'satisfaccion'],
                    ['function' => 'COUNT', 'alias' => 'respuestas'],
                ],
                groupBy: ['version_id', 'evento'],
                orderBy: 'satisfaccion',
                limit: ReportQueryService::MAX_LIMIT
            ),
            $filters
        );

        $qualified = array_values(array_filter(
            $ranked,
            fn (array $row) => (int) $row['respuestas'] >= $minResponses
        ));

        foreach ($qualified as $index => $row) {
            $qualified[$index]['satisfaccion'] = round((float) $row['satisfaccion'], 2);
        }

        return [
            'top' => array_slice($qualified, 0, $topN),
            'versiones_evaluadas' => count($qualified),
            'scope' => $scope,
        ];
    }
}
