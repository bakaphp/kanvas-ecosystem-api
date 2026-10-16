<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ReadsActivityForEntity;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesOrganizationForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Read Organization Activity', category: 'crm')]
class ReadOrganizationActivityTool extends Tool
{
    use ReadsActivityForEntity;
    use ResolvesOrganizationForTool;
    use TrackByInputs;

    protected string $name = 'read_organization_activity';

    protected ?string $description = 'Read an organization\'s (account\'s) Activity thread — the notes, logged calls, emails and system '
        . 'events the team sees on that account, newest first. Use it before summarizing an account or preparing '
        . 'a renewal or check-in. Identify the account by organization_id or organization_name.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'organization_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the organization whose activity to read.',
                required: false,
            ),
            new ToolProperty(
                name: 'organization_name',
                type: PropertyType::STRING,
                description: 'The organization name, when you do not have its id.',
                required: false,
            ),
            ...$this->paginationProperties(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?int $organization_id = null,
        ?string $organization_name = null,
        ?int $limit = null,
        ?int $before_id = null,
    ): array {
        $refusal = $this->customerSurfaceRefusal();
        if ($refusal !== null) {
            return $refusal;
        }

        $organization = $this->resolveOrganization($organization_id, $organization_name);
        if (is_array($organization)) {
            return $organization;
        }

        return $this->readActivity(
            $organization,
            'organization_id',
            (string) $organization->name,
            $limit,
            $before_id
        );
    }
}
