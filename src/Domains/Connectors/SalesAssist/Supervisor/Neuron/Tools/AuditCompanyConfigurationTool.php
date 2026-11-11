<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Supervisor\Neuron\Tools;

use Kanvas\Connectors\SalesAssist\Supervisor\Services\SupervisorConfigurationAuditService;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\Tool;

/**
 * Deliberately has no #[AgentTool]: the catalog would let any agent be granted it, and this type
 * carries it in its own tools().
 */
class AuditCompanyConfigurationTool extends Tool
{
    use HasKanvasContext;

    protected string $name = 'audit_company_configuration';

    protected ?string $description = 'Read-only check of this dealer\'s settings, rules, agents, receivers, pipelines '
        . 'and follow-up delivery against what SalesAssist needs to operate. Returns findings with severity and evidence ids. '
        . 'It never returns a setting value and changes nothing.';

    /**
     * @return array{status: string, findings: list<array<string, mixed>>}
     */
    public function __invoke(): array
    {
        if (! $this->hasTenantContext()) {
            return ['status' => 'error', 'findings' => []];
        }

        return [
            'status' => 'success',
            'findings' => new SupervisorConfigurationAuditService($this->app, $this->company)->execute(),
        ];
    }
}
