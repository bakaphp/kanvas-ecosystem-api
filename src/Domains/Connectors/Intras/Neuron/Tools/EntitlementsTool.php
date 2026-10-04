<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Neuron\Tools;

use Kanvas\Analytics\Reporting\DataTransferObject\AggregateRequest;
use Kanvas\Analytics\Reporting\DataTransferObject\ReportFilter;
use Kanvas\Analytics\Reporting\Services\ReportQueryService;
use Kanvas\Analytics\Reporting\Support\ReportRegistry;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Plan consumption and courtesy passes — the legacy "Reporte de Consumo" and "Pases de Cortesía".
 *
 * Two entitlement questions with the same shape: what a company was granted, what it has used,
 * and what is left. They share a tool because a caller asking "what does this company still have
 * available" wants both, and because neither is worth its own name in the tool list.
 */
#[AgentTool(name: 'Intras Entitlements', category: 'reporting')]
class EntitlementsTool extends Tool
{
    use TrackByInputs;
    use HasKanvasContext;

    protected string $name = 'intras_entitlements';

    protected ?string $description = 'Consumo de planes y pases de cortesía por empresa. kind: "planes" '
        . '(cupos contratados, usados y disponibles por plan) o "cortesias" (pases '
        . 'emitidos, usados, vigentes y vencidos). Filtra por empresa, estado y si el '
        . 'plan ya está consumido.';

    private const array KINDS = ['planes', 'cortesias'];

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'kind', type: PropertyType::STRING, description: 'planes | cortesias. Por defecto planes.', required: false),
            new ToolProperty(name: 'empresa', type: PropertyType::STRING, description: 'Nombre de la empresa (búsqueda parcial).', required: false),
            new ToolProperty(name: 'estado', type: PropertyType::STRING, description: 'Estado del plan o del pase.', required: false),
            new ToolProperty(name: 'solo_disponibles', type: PropertyType::BOOLEAN, description: 'En planes: sólo con cupos disponibles. En cortesías: sólo vigentes.', required: false),
            new ToolProperty(name: 'limit', type: PropertyType::INTEGER, description: 'Máximo de filas. Por defecto 25.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $kind = null,
        ?string $empresa = null,
        ?string $estado = null,
        ?bool $solo_disponibles = null,
        ?int $limit = null
    ): array {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('entitlements');
        }

        $kind = mb_strtolower($kind ?? 'planes');

        if (! in_array($kind, self::KINDS, true)) {
            return ['error' => 'kind debe ser uno de: ' . implode(', ', self::KINDS) . '.'];
        }

        try {
            return $kind === 'planes'
                ? $this->plans($empresa, $estado, $solo_disponibles === true, $limit ?? 25)
                : $this->courtesies($empresa, $estado, $solo_disponibles === true, $limit ?? 25);
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function plans(
        ?string $empresa,
        ?string $estado,
        bool $onlyAvailable,
        int $limit
    ): array {
        $service = new ReportQueryService();
        $definition = new ReportRegistry()->find($this->app, 'empresa_plan');

        $filters = [];

        if ($empresa !== null && $empresa !== '') {
            $filters[] = new ReportFilter('empresa', 'LIKE', '%' . $empresa . '%');
        }

        if ($estado !== null && $estado !== '') {
            $filters[] = new ReportFilter('estado', '=', $estado);
        }

        if ($onlyAvailable) {
            $filters[] = new ReportFilter('disponibles', '>', 0);
        }

        $totals = $service->aggregate(
            $definition,
            $this->app,
            $this->company,
            AggregateRequest::fromInput(
                aggregates: [
                    ['function' => 'COUNT', 'alias' => 'planes'],
                    ['function' => 'COUNT_DISTINCT', 'column' => 'organizations_id', 'alias' => 'empresas'],
                    ['function' => 'SUM', 'column' => 'cupos', 'alias' => 'cupos'],
                    ['function' => 'SUM', 'column' => 'usados', 'alias' => 'usados'],
                    ['function' => 'SUM', 'column' => 'disponibles', 'alias' => 'disponibles'],
                ],
                limit: 1
            ),
            $filters
        );

        return [
            'kind' => 'planes',
            'resumen' => [
                'planes' => (int) ($totals[0]['planes'] ?? 0),
                'empresas' => (int) ($totals[0]['empresas'] ?? 0),
                'cupos' => (int) ($totals[0]['cupos'] ?? 0),
                'usados' => (int) ($totals[0]['usados'] ?? 0),
                'disponibles' => (int) ($totals[0]['disponibles'] ?? 0),
            ],
            'detalle' => $service->search(
                $definition,
                $this->app,
                $this->company,
                $filters,
                select: ['empresa', 'plan', 'tier', 'cupos', 'usados', 'disponibles', 'estado', 'fecha_expiracion', 'consumido'],
                limit: $limit
            ),
            'scope' => array_filter([
                'empresa' => $empresa,
                'estado' => $estado,
                'solo_disponibles' => $onlyAvailable ? 'sí' : null,
            ], fn ($v) => $v !== null && $v !== ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function courtesies(
        ?string $empresa,
        ?string $estado,
        bool $onlyValid,
        int $limit
    ): array {
        $service = new ReportQueryService();
        $definition = new ReportRegistry()->find($this->app, 'cortesia');

        $filters = [];

        if ($empresa !== null && $empresa !== '') {
            $filters[] = new ReportFilter('empresa', 'LIKE', '%' . $empresa . '%');
        }

        if ($estado !== null && $estado !== '') {
            $filters[] = new ReportFilter('estado', '=', $estado);
        }

        if ($onlyValid) {
            $filters[] = new ReportFilter('vigente', '=', 1);
        }

        $totals = $service->aggregate(
            $definition,
            $this->app,
            $this->company,
            AggregateRequest::fromInput(
                aggregates: [
                    ['function' => 'COUNT', 'alias' => 'pases'],
                    ['function' => 'COUNT_DISTINCT', 'column' => 'empresa_id', 'alias' => 'empresas'],
                    ['function' => 'SUM', 'column' => 'vigente', 'alias' => 'vigentes'],
                ],
                limit: 1
            ),
            $filters
        );

        return [
            'kind' => 'cortesias',
            'resumen' => [
                'pases' => (int) ($totals[0]['pases'] ?? 0),
                'empresas' => (int) ($totals[0]['empresas'] ?? 0),
                'vigentes' => (int) ($totals[0]['vigentes'] ?? 0),
            ],
            'por_estado' => $service->aggregate(
                $definition,
                $this->app,
                $this->company,
                AggregateRequest::fromInput(
                    aggregates: [['function' => 'COUNT', 'alias' => 'pases']],
                    groupBy: ['estado'],
                    orderBy: 'pases',
                    limit: 20
                ),
                $filters
            ),
            'detalle' => $service->search(
                $definition,
                $this->app,
                $this->company,
                $filters,
                select: ['codigo', 'empresa', 'ejecutivo', 'evento', 'motivo', 'tipo', 'estado', 'vigente', 'fecha_emision', 'fecha_expiracion', 'fecha_uso'],
                limit: $limit
            ),
            'scope' => array_filter([
                'empresa' => $empresa,
                'estado' => $estado,
                'solo_vigentes' => $onlyValid ? 'sí' : null,
            ], fn ($v) => $v !== null && $v !== ''),
        ];
    }
}
