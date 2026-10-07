<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CompanyConfiguration;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\ListAgentsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\RunsInExplicitCompany;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * list_agents for the Company Configuration Administrator: the stable roster, readable in an
 * explicitly authorized company. The base tool is untouched; only this type carries company_uuid.
 */
class AppScopedListAgentsTool extends ListAgentsTool
{
    use RunsInExplicitCompany;

    public function __construct(private readonly ?Agent $agent = null)
    {
        parent::__construct($agent);
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [...parent::properties(), $this->companyUuidProperty()];
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function __invoke(
        ?string $capability = null,
        ?string $search = null,
        ?bool $executors_only = null,
        ?int $limit = null,
        ?string $company_uuid = null,
    ): array {
        if ($company_uuid !== null) {
            return $this->inExplicitCompany($company_uuid, $this->agent ?? $this->contextAgent(), fn (self $tool): array => $tool(
                $capability,
                $search,
                $executors_only,
                $limit,
            ));
        }

        return parent::__invoke(
            $capability,
            $search,
            $executors_only,
            $limit,
        );
    }
}
