<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Connectors\Apollo\Services\PeopleBouncesFeedService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * A readable sample of people with bad emails for the agent to talk about. For the full
 * downloadable list use export_bounces. Scoped to the agent's current company.
 */
#[AgentTool(name: 'List Bounces', category: 'crm')]
class ListBouncesTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'list_bounces',
            description: 'Lists people whose email is a dead address (hard bounce / invalid). Use for "who has bad '
                . 'emails?", "show me bouncing contacts". By default counts permanent failures only (matching the '
                . 'bouncingPeople metric); pass include_soft_bounce to also include recoverable soft bounces. For '
                . 'the full downloadable list use export_bounces instead.',
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
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Max people to return. Defaults to 25, max 100.',
                required: false,
            ),
            new ToolProperty(
                name: 'include_soft_bounce',
                type: PropertyType::BOOLEAN,
                description: 'When true, also include recoverable soft bounces. Defaults to false (permanent failures only).',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?int $limit = null, ?bool $include_soft_bounce = null): array
    {
        $limit = max(1, min(100, $limit ?? 25));

        $rows = new PeopleBouncesFeedService($this->app, $this->company)
            ->rows(
                includeSoftBounce: (bool) $include_soft_bounce,
                granularity: 'per_person',
                limit: $limit,
            );

        return [
            'count' => count($rows),
            'bounces' => array_map(
                fn (array $row): array => [
                    'crm' => $row['crm'],
                    'person' => $row['person'],
                    'company' => $row['company'],
                    'email' => $row['email'],
                    'status' => $row['status'],
                ],
                $rows,
            ),
        ];
    }
}
