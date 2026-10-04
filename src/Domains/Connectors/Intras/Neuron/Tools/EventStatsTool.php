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
 * How many event versions were held, cancelled, or repeated in a period.
 *
 * `realizados` and `cancelados` are the same query with opposite status predicates, which is why
 * they are one tool. The cancellation count only became answerable once version status was
 * actually migrated — every version used to import as ACTIVO, hiding agency 1's 303
 * cancellations.
 *
 * `repetidos` is the one mode `run_report` cannot express: it needs the same evento appearing on
 * more than one version inside the window, which is a HAVING over a grouping.
 */
#[AgentTool(name: 'Intras Event Stats', category: 'reporting')]
class EventStatsTool extends Tool
{
    use TrackByInputs;
    use BuildsIntrasFilters;
    use HasKanvasContext;

    protected string $name = 'intras_event_stats';

    protected ?string $description = 'Cuántos eventos (versiones) se hicieron, se cancelaron, o se repitieron '
        . 'en un periodo, por tipo (ABIERTO/IN-HOUSE), clase y categoría. mode: '
        . '"realizados" (por defecto), "cancelados" o "repetidos" (mismo evento con más '
        . 'de una versión en el periodo).';

    private const array MODES = ['realizados', 'cancelados', 'repetidos'];

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'mode', type: PropertyType::STRING, description: 'realizados | cancelados | repetidos.', required: false),
            new ToolProperty(name: 'tipo', type: PropertyType::STRING, description: 'ABIERTO o IN-HOUSE.', required: false),
            new ToolProperty(name: 'clase', type: PropertyType::STRING, description: 'Clase de evento.', required: false),
            new ToolProperty(name: 'categoria', type: PropertyType::STRING, description: 'Categoría de evento.', required: false),
            new ToolProperty(name: 'desde', type: PropertyType::STRING, description: 'Fecha inicial YYYY-MM-DD.', required: false),
            new ToolProperty(name: 'hasta', type: PropertyType::STRING, description: 'Fecha final YYYY-MM-DD.', required: false),
            new ToolProperty(name: 'breakdown_by', type: PropertyType::STRING, description: 'Columna para desglosar, p. ej. "clase", "categoria", "estatus".', required: false),
            new ToolProperty(name: 'limit', type: PropertyType::INTEGER, description: 'Máximo de filas. Por defecto 25.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $mode = null,
        ?string $tipo = null,
        ?string $clase = null,
        ?string $categoria = null,
        ?string $desde = null,
        ?string $hasta = null,
        ?string $breakdown_by = null,
        ?int $limit = null
    ): array {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('event stats');
        }

        $mode = mb_strtolower($mode ?? 'realizados');

        if (! in_array($mode, self::MODES, true)) {
            return ['error' => 'mode debe ser uno de: ' . implode(', ', self::MODES) . '.'];
        }

        try {
            $definition = new ReportRegistry()->find($this->app, 'evento_version');
            $service = new ReportQueryService();

            $filters = $this->periodFilters('fecha_inicio', $desde, $hasta);

            foreach (['tipo' => $tipo, 'clase' => $clase, 'categoria' => $categoria] as $column => $value) {
                if ($value !== null && $value !== '') {
                    $filters[] = new ReportFilter($column, '=', mb_strtoupper($value));
                }
            }

            $filters[] = $mode === 'cancelados'
                ? new ReportFilter('estatus', '=', IntrasGoalPolicy::CANCELLED_STATUS)
                : new ReportFilter('estatus', '!=', IntrasGoalPolicy::CANCELLED_STATUS);

            if ($mode === 'repetidos') {
                return $this->repeated($definition, $service, $filters, $limit ?? 25, $tipo, $clase, $desde, $hasta);
            }

            $totals = $service->aggregate(
                $definition,
                $this->app,
                $this->company,
                AggregateRequest::fromInput(
                    aggregates: [['function' => 'COUNT', 'alias' => 'total']],
                    limit: 1
                ),
                $filters
            );

            $result = [
                'mode' => $mode,
                'total_versiones' => (int) ($totals[0]['total'] ?? 0),
                'scope' => array_filter([
                    'estatus' => $mode === 'cancelados' ? IntrasGoalPolicy::CANCELLED_STATUS : 'excluye canceladas',
                    'tipo' => $tipo,
                    'clase' => $clase,
                    'categoria' => $categoria,
                    'desde' => $desde,
                    'hasta' => $hasta,
                ], fn ($v) => $v !== null && $v !== ''),
            ];

            if ($breakdown_by !== null && $breakdown_by !== '') {
                $result['breakdown'] = $service->aggregate(
                    $definition,
                    $this->app,
                    $this->company,
                    AggregateRequest::fromInput(
                        aggregates: [['function' => 'COUNT', 'alias' => 'total']],
                        groupBy: [$breakdown_by],
                        orderBy: 'total',
                        limit: $limit ?? 25
                    ),
                    $filters
                );
            }

            return $result;
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Eventos with more than one version in the window — "¿qué eventos tuvimos que repetir?".
     *
     * @param array<int, ReportFilter> $filters
     *
     * @return array<string, mixed>
     */
    private function repeated(
        object $definition,
        ReportQueryService $service,
        array $filters,
        int $limit,
        ?string $tipo,
        ?string $clase,
        ?string $desde,
        ?string $hasta
    ): array {
        $grouped = $service->aggregate(
            $definition,
            $this->app,
            $this->company,
            AggregateRequest::fromInput(
                aggregates: [['function' => 'COUNT', 'alias' => 'versiones']],
                groupBy: ['evento'],
                orderBy: 'versiones',
                limit: ReportQueryService::MAX_LIMIT
            ),
            $filters
        );

        // The HAVING is applied here rather than in SQL: the aggregate surface deliberately has
        // no HAVING, and filtering a bounded grouping in PHP is cheaper than widening it.
        $repeated = array_values(array_filter($grouped, fn (array $row) => (int) $row['versiones'] > 1));

        return [
            'mode' => 'repetidos',
            'total_eventos_repetidos' => count($repeated),
            'eventos' => array_slice($repeated, 0, $limit),
            'scope' => array_filter([
                'tipo' => $tipo,
                'clase' => $clase,
                'desde' => $desde,
                'hasta' => $hasta,
                'criterio' => 'más de una versión en el periodo, excluye canceladas',
            ], fn ($v) => $v !== null && $v !== ''),
        ];
    }
}
