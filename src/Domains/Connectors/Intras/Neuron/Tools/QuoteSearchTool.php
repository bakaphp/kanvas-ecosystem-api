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
 * Which companies asked for a proposal — by facilitator, by theme, by status.
 *
 * `facilitador` and `tema` are membership tests against JSON arrays with multi-valued indexes,
 * which is why they are parameters here rather than something an agent has to know to express as
 * `MEMBER OF`. Those arrays only exist because the lead importer learned to read
 * `quotes_facilitators` (5,960 rows), `quotes_events` (3,200) and `quotes_keywords` (4,775) —
 * none of which had ever reached Kanvas.
 */
#[AgentTool(name: 'Intras Quote Search', category: 'reporting')]
class QuoteSearchTool extends Tool implements HasRunKey
{
    use TrackByInputs;
    use BuildsIntrasFilters;
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'intras_quote_search',
            description: 'Busca cotizaciones (propuestas in-house). Filtra por facilitador '
                . 'solicitado, tema, evento solicitado, empresa, estatus, si fue aprobada, y '
                . 'rango de fechas. Responde "¿qué empresas han solicitado propuestas del '
                . 'facilitador X?" y "¿de temas relacionados a Y?". Con group_by="empresa" '
                . 'devuelve el conteo por empresa.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'facilitador', type: PropertyType::STRING, description: 'Nombre exacto del facilitador solicitado.', required: false),
            new ToolProperty(name: 'tema', type: PropertyType::STRING, description: 'Tema / keyword exacto.', required: false),
            new ToolProperty(name: 'evento_solicitado', type: PropertyType::STRING, description: 'Nombre exacto del evento solicitado.', required: false),
            new ToolProperty(name: 'empresa', type: PropertyType::STRING, description: 'Nombre de la empresa (búsqueda parcial).', required: false),
            new ToolProperty(name: 'estatus', type: PropertyType::STRING, description: 'Aprobada, Rechazada, Esperando Aprobación, ...', required: false),
            new ToolProperty(name: 'solo_aprobadas', type: PropertyType::BOOLEAN, description: 'true para sólo propuestas aprobadas.', required: false),
            new ToolProperty(name: 'desde', type: PropertyType::STRING, description: 'Fecha de solicitud desde, YYYY-MM-DD.', required: false),
            new ToolProperty(name: 'hasta', type: PropertyType::STRING, description: 'Fecha de solicitud hasta, YYYY-MM-DD.', required: false),
            new ToolProperty(name: 'group_by', type: PropertyType::STRING, description: 'Agrupar en vez de listar: "empresa", "estatus", "area", "sector".', required: false),
            new ToolProperty(name: 'limit', type: PropertyType::INTEGER, description: 'Máximo de filas. Por defecto 25.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $facilitador = null,
        ?string $tema = null,
        ?string $evento_solicitado = null,
        ?string $empresa = null,
        ?string $estatus = null,
        ?bool $solo_aprobadas = null,
        ?string $desde = null,
        ?string $hasta = null,
        ?string $group_by = null,
        ?int $limit = null
    ): array {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('quote search');
        }

        try {
            $definition = new ReportRegistry()->find($this->app, 'cotizacion');
            $service = new ReportQueryService();

            $filters = $this->periodFilters('fecha_solicitud', $desde, $hasta);

            foreach ([
                'facilitadores' => $facilitador,
                'temas' => $tema,
                'eventos_solicitados' => $evento_solicitado,
            ] as $column => $value) {
                if ($value !== null && $value !== '') {
                    $filters[] = new ReportFilter($column, 'MEMBER OF', $value);
                }
            }

            if ($empresa !== null && $empresa !== '') {
                $filters[] = new ReportFilter('empresa', 'LIKE', '%' . $empresa . '%');
            }

            if ($estatus !== null && $estatus !== '') {
                $filters[] = new ReportFilter('estatus', '=', $estatus);
            }

            if ($solo_aprobadas === true) {
                $filters[] = new ReportFilter('es_aprobada', '=', 1);
            }

            $scope = array_filter([
                'facilitador' => $facilitador,
                'tema' => $tema,
                'evento_solicitado' => $evento_solicitado,
                'empresa' => $empresa,
                'estatus' => $estatus,
                'solo_aprobadas' => $solo_aprobadas === true ? 'sí' : null,
                'desde' => $desde,
                'hasta' => $hasta,
            ], fn ($v) => $v !== null && $v !== '');

            $totals = $service->aggregate(
                $definition,
                $this->app,
                $this->company,
                AggregateRequest::fromInput(
                    aggregates: [
                        ['function' => 'COUNT', 'alias' => 'cotizaciones'],
                        ['function' => 'COUNT_DISTINCT', 'column' => 'empresa_id', 'alias' => 'empresas'],
                        ['function' => 'SUM', 'column' => 'monto', 'alias' => 'monto_total'],
                    ],
                    limit: 1
                ),
                $filters
            );

            $result = [
                'cotizaciones' => (int) ($totals[0]['cotizaciones'] ?? 0),
                'empresas' => (int) ($totals[0]['empresas'] ?? 0),
                'monto_total' => round((float) ($totals[0]['monto_total'] ?? 0), 2),
                'scope' => $scope,
            ];

            if ($group_by !== null && $group_by !== '') {
                $result['breakdown'] = $service->aggregate(
                    $definition,
                    $this->app,
                    $this->company,
                    AggregateRequest::fromInput(
                        aggregates: [
                            ['function' => 'COUNT', 'alias' => 'cotizaciones'],
                            ['function' => 'SUM', 'column' => 'monto', 'alias' => 'monto'],
                        ],
                        groupBy: [$group_by],
                        orderBy: 'cotizaciones',
                        limit: $limit ?? 25
                    ),
                    $filters
                );

                return $result;
            }

            $result['cotizaciones_detalle'] = $service->search(
                $definition,
                $this->app,
                $this->company,
                $filters,
                select: ['numero', 'empresa', 'estatus', 'es_aprobada', 'monto', 'area', 'fecha_solicitud', 'facilitadores', 'temas'],
                limit: $limit ?? 25
            );

            return $result;
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
