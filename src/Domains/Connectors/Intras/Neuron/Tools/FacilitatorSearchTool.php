<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Neuron\Tools;

use Kanvas\Analytics\Reporting\DataTransferObject\AggregateRequest;
use Kanvas\Analytics\Reporting\DataTransferObject\ReportFilter;
use Kanvas\Analytics\Reporting\Services\ReportQueryService;
use Kanvas\Analytics\Reporting\Support\ReportRegistry;
use Kanvas\Connectors\Intras\Reporting\IntrasGoalPolicy;
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
 * Which facilitator could deliver a seminar on a given theme, and what they have delivered.
 *
 * `temas` is a JSON array with a multi-valued index, so theme matching is a membership test
 * rather than a LIKE over a concatenated string.
 */
#[AgentTool(name: 'Intras Facilitator Search', category: 'reporting')]
class FacilitatorSearchTool extends Tool implements HasRunKey
{
    use TrackByInputs;
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'intras_facilitator_search',
            description: 'Busca facilitadores por tema, idioma, país o aliado, y devuelve su '
                . 'historial de versiones impartidas. Responde "¿qué facilitador podría impartir '
                . 'un seminario del tema X?". Con historial=true incluye las versiones asignadas '
                . 'y su satisfacción.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'tema', type: PropertyType::STRING, description: 'Tema exacto que domina el facilitador.', required: false),
            new ToolProperty(name: 'nombre', type: PropertyType::STRING, description: 'Nombre del facilitador (búsqueda parcial).', required: false),
            new ToolProperty(name: 'idioma', type: PropertyType::STRING, description: 'Idioma exacto.', required: false),
            new ToolProperty(name: 'pais', type: PropertyType::STRING, description: 'País.', required: false),
            new ToolProperty(name: 'aliado', type: PropertyType::STRING, description: 'Aliado / marca.', required: false),
            new ToolProperty(name: 'historial', type: PropertyType::BOOLEAN, description: 'true para incluir las asignaciones del facilitador.', required: false),
            new ToolProperty(name: 'limit', type: PropertyType::INTEGER, description: 'Máximo de facilitadores. Por defecto 25.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $tema = null,
        ?string $nombre = null,
        ?string $idioma = null,
        ?string $pais = null,
        ?string $aliado = null,
        ?bool $historial = null,
        ?int $limit = null
    ): array {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('facilitator search');
        }

        try {
            $service = new ReportQueryService();
            $definition = new ReportRegistry()->find($this->app, 'facilitador');

            $filters = [];

            foreach (['temas' => $tema, 'idiomas' => $idioma] as $column => $value) {
                if ($value !== null && $value !== '') {
                    $filters[] = new ReportFilter($column, 'MEMBER OF', $value);
                }
            }

            if ($nombre !== null && $nombre !== '') {
                $filters[] = new ReportFilter('nombre_completo', 'LIKE', '%' . $nombre . '%');
            }

            foreach (['pais' => $pais, 'aliado' => $aliado] as $column => $value) {
                if ($value !== null && $value !== '') {
                    $filters[] = new ReportFilter($column, '=', $value);
                }
            }

            $facilitators = $service->search(
                $definition,
                $this->app,
                $this->company,
                $filters,
                select: ['facilitator_id', 'nombre_completo', 'estatus', 'aliado', 'pais', 'email', 'temas', 'idiomas', 'total_versiones', 'ultima_version'],
                limit: $limit ?? 25
            );

            $result = [
                'facilitadores' => $facilitators,
                'total' => count($facilitators),
                'scope' => array_filter([
                    'tema' => $tema,
                    'nombre' => $nombre,
                    'idioma' => $idioma,
                    'pais' => $pais,
                    'aliado' => $aliado,
                ], fn ($v) => $v !== null && $v !== ''),
            ];

            if ($historial === true && $facilitators !== []) {
                $ids = array_map(fn (array $f) => (int) $f['facilitator_id'], $facilitators);

                $result['asignaciones'] = $service->aggregate(
                    new ReportRegistry()->find($this->app, 'facilitador_asignacion'),
                    $this->app,
                    $this->company,
                    AggregateRequest::fromInput(
                        aggregates: [
                            ['function' => 'COUNT', 'alias' => 'versiones'],
                            ['function' => 'AVG', 'column' => 'inscripciones', 'alias' => 'promedio_inscripciones'],
                            ['function' => 'MAX', 'column' => 'fecha_inicio', 'alias' => 'ultima'],
                        ],
                        groupBy: ['facilitator_id', 'facilitador'],
                        orderBy: 'versiones',
                        limit: $limit ?? 25
                    ),
                    [
                        new ReportFilter('facilitator_id', 'IN', $ids),
                        new ReportFilter('estatus_version', '!=', IntrasGoalPolicy::CANCELLED_STATUS),
                    ]
                );
            }

            return $result;
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
