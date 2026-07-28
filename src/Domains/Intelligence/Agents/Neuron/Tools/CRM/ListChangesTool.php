<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Connectors\Apollo\Services\PeopleChangesFeedService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * A readable sample of detected people changes for the agent to talk about. For the full
 * downloadable list use export_changes. Scoped to the agent's current company.
 */
#[AgentTool(name: 'List Changes', category: 'crm')]
class ListChangesTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'list_changes',
            description: 'Lists people whose company, title, email changed or who were promoted, with before → '
                . 'after values. Use to describe or preview changes ("who changed jobs?", "show me recent email '
                . 'changes"). Filter by change_types: company, title, email, promotion. For the full downloadable '
                . 'list use export_changes instead.',
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
                name: 'change_types',
                type: PropertyType::ARRAY,
                description: 'Optional list of change types to include: any of "company", "title", "email", "promotion". Omit for all types.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Max change rows to return, most recent first. Defaults to 25, max 100.',
                required: false,
            ),
        ];
    }

    /**
     * @param list<string>|null $change_types
     *
     * @return array<string, mixed>
     */
    public function __invoke(?array $change_types = null, ?int $limit = null): array
    {
        $limit = max(1, min(100, $limit ?? 25));

        $rows = new PeopleChangesFeedService($this->app, $this->company)
            ->rows(changeTypes: $change_types, limit: $limit);

        return [
            'count' => count($rows),
            'changes' => array_map(
                fn (array $row): array => [
                    'crm' => $row['crm'],
                    'person' => $row['person'],
                    'company' => $row['company'],
                    'type' => $row['type'],
                    'from' => $row['from'],
                    'to' => $row['to'],
                    'occurred_at' => $row['occurred_at'],
                ],
                $rows,
            ),
        ];
    }
}
