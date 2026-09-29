<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Neuron\Tools;

use Kanvas\Analytics\Reporting\DataTransferObject\AggregateRequest;
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
 * How many ejecutivos, empresas or inscripciones in a period, by event type and class.
 *
 * Exists because the generic `run_report` lets the agent compose this wrong and get a plausible
 * number back. "Participó" means `tipo_inscripcion IN (CONFIRMADO, CONFIRMADO PLAN, PROGRAMA)`
 * and excludes cancelled versions; without both, distinct ejecutivos in 2025 open seminars comes
 * back as 1,053 instead of 812. Those rules are in `BuildsIntrasFilters` and cannot be omitted
 * from here.
 */
#[AgentTool(name: 'Intras Participation Stats', category: 'reporting')]
class ParticipationStatsTool extends Tool implements HasRunKey
{
    use TrackByInputs;
    use BuildsIntrasFilters;
    use HasKanvasContext;

    private const array COUNT_BY = ['ejecutivos', 'empresas', 'inscripciones'];

    public function __construct()
    {
        parent::__construct(
            name: 'intras_participation_stats',
            description: 'Cuántos ejecutivos, empresas o inscripciones participaron en eventos, '
                . 'filtrando por tipo (ABIERTO/IN-HOUSE), clase (SEMINARIO, EVENTO GRANDE, ...), '
                . 'categoría, línea temática y rango de fechas. Aplica automáticamente la '
                . 'definición de INTRAS de "participó" (CONFIRMADO, CONFIRMADO PLAN, PROGRAMA) y '
                . 'excluye versiones canceladas. Usa breakdown_by para desglosar (por ejemplo '
                . '"sexo", "empresa", "clase", "sector").',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'count_by',
                type: PropertyType::STRING,
                description: 'Qué contar: "ejecutivos" (personas distintas), "empresas" '
                    . '(organizaciones distintas) o "inscripciones" (filas). Por defecto ejecutivos.',
                required: false,
            ),
            new ToolProperty(name: 'tipo', type: PropertyType::STRING, description: 'ABIERTO o IN-HOUSE.', required: false),
            new ToolProperty(name: 'clase', type: PropertyType::STRING, description: 'SEMINARIO, EVENTO GRANDE, PROGRAMA INTERNACIONAL, ...', required: false),
            new ToolProperty(name: 'categoria', type: PropertyType::STRING, description: 'Categoría del evento.', required: false),
            new ToolProperty(name: 'linea_tematica', type: PropertyType::STRING, description: 'Línea temática.', required: false),
            new ToolProperty(name: 'desde', type: PropertyType::STRING, description: 'Fecha inicial YYYY-MM-DD (fecha de inicio del evento).', required: false),
            new ToolProperty(name: 'hasta', type: PropertyType::STRING, description: 'Fecha final YYYY-MM-DD.', required: false),
            new ToolProperty(
                name: 'breakdown_by',
                type: PropertyType::STRING,
                description: 'Columna para desglosar el resultado, por ejemplo "sexo", "clase", '
                    . '"empresa", "sector", "nivel". Omitir para obtener sólo el total.',
                required: false,
            ),
            new ToolProperty(name: 'limit', type: PropertyType::INTEGER, description: 'Máximo de filas del desglose. Por defecto 25.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $count_by = null,
        ?string $tipo = null,
        ?string $clase = null,
        ?string $categoria = null,
        ?string $linea_tematica = null,
        ?string $desde = null,
        ?string $hasta = null,
        ?string $breakdown_by = null,
        ?int $limit = null
    ): array {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('participation stats');
        }

        $countBy = mb_strtolower($count_by ?? 'ejecutivos');

        if (! in_array($countBy, self::COUNT_BY, true)) {
            return ['error' => 'count_by debe ser uno de: ' . implode(', ', self::COUNT_BY) . '.'];
        }

        try {
            $definition = new ReportRegistry()->find($this->app, 'inscripcion');
            $service = new ReportQueryService();

            $filters = array_merge(
                $this->participationFilters($tipo, $clase, $categoria, $linea_tematica),
                $this->periodFilters('fecha_inicio', $desde, $hasta),
            );

            [$function, $column] = match ($countBy) {
                'ejecutivos' => ['COUNT_DISTINCT', 'peoples_id'],
                'empresas' => ['COUNT_DISTINCT', 'empresa_id'],
                default => ['COUNT', null],
            };

            $aggregates = [['function' => $function, 'column' => $column, 'alias' => 'total']];

            $totals = $service->aggregate(
                $definition,
                $this->app,
                $this->company,
                AggregateRequest::fromInput(aggregates: $aggregates, limit: 1),
                $filters
            );

            $result = [
                'count_by' => $countBy,
                'total' => (int) ($totals[0]['total'] ?? 0),
                'scope' => $this->describeScope([
                    'tipo' => $tipo,
                    'clase' => $clase,
                    'categoria' => $categoria,
                    'linea_tematica' => $linea_tematica,
                    'desde' => $desde,
                    'hasta' => $hasta,
                ]),
            ];

            if ($breakdown_by !== null && $breakdown_by !== '') {
                $result['breakdown'] = $service->aggregate(
                    $definition,
                    $this->app,
                    $this->company,
                    AggregateRequest::fromInput(
                        aggregates: $aggregates,
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
}
