<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * The global counts the agent lacks when asked "how many customers do we have, and the last 10
 * added?" — a total + a most-recent list, not a name-filtered search. Company-scoped: an
 * internal-teammate capability, not the customer-facing surface.
 */
#[AgentTool(name: 'Get Customer Stats', category: 'crm')]
class GetCustomerStatsTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'get_customer_stats',
            description: 'Global CRM counts: how many customer organizations and how many people (contacts) are in '
                . 'the database, plus the most recently added customers. Use for "how many customers do we have?", '
                . '"how many contacts are in the system?", "show me the last 10 customers we added". This is the '
                . 'aggregate/recent view — use find_customer or search_leads when you have a specific name to look up.',
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
                description: 'How many of the most recently added customers to list. Defaults to 10, max 50.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?int $limit = null): array
    {
        $limit = max(1, min(50, $limit ?? 10));

        $totalCustomers = (int) Organization::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->count();

        $totalPeople = (int) People::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->count();

        $recent = Organization::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'name', 'created_at']);

        return [
            'total_customers' => $totalCustomers,
            'total_people' => $totalPeople,
            'recent_customers' => $recent->map(fn (Organization $org): array => [
                'organization_id' => $org->getId(),
                'name' => $org->name,
                'created_at' => $org->created_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
