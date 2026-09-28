<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Events;

use Illuminate\Support\Carbon;
use Kanvas\Event\Reports\Enums\OrgActivityFilterEnum;
use Kanvas\Event\Reports\Enums\OrgActivityOrderEnum;
use Kanvas\Event\Reports\Repositories\OrganizationEventActivityRepository;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Organizations ranked by event participation over a period — including which have gone inactive
 * (no recent participation despite prior activity). Delegates to the same report repository the
 * dashboard uses. Company-scoped.
 */
#[AgentTool(name: 'Get Org Activity', category: 'events')]
class GetOrgActivityTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'get_org_activity',
            description: 'Organizations ranked by event participation over a period, including which have gone '
                . 'inactive (no recent participation despite prior activity). Use for "which companies stopped '
                . 'coming", "inactive accounts", "most active organizations". activity filters to '
                . 'all/active/inactive/lapsed/new.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'from_date', type: PropertyType::STRING, description: 'Period start as an ISO date (YYYY-MM-DD).', required: false),
            new ToolProperty(name: 'to_date', type: PropertyType::STRING, description: 'Period end as an ISO date (YYYY-MM-DD).', required: false),
            new ToolProperty(
                name: 'activity',
                type: PropertyType::STRING,
                description: 'Which organizations to include. Defaults to "all".',
                required: false,
                enum: ['all', 'active', 'inactive', 'lapsed', 'new'],
            ),
            new ToolProperty(name: 'min_count', type: PropertyType::INTEGER, description: 'Only orgs with at least this many participations.', required: false),
            new ToolProperty(name: 'max_count', type: PropertyType::INTEGER, description: 'Only orgs with at most this many participations.', required: false),
            new ToolProperty(name: 'top_n', type: PropertyType::INTEGER, description: 'Keep only the top-N organizations.', required: false),
            new ToolProperty(
                name: 'order_by',
                type: PropertyType::STRING,
                description: 'Sort order. Defaults to "count_desc".',
                required: false,
                enum: ['count_desc', 'count_asc', 'last_date_desc', 'first_date_asc', 'name_asc'],
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $from_date = null,
        ?string $to_date = null,
        ?string $activity = null,
        ?int $min_count = null,
        ?int $max_count = null,
        ?int $top_n = null,
        ?string $order_by = null,
    ): array {
        $rows = OrganizationEventActivityRepository::query(
            app: $this->app,
            company: $this->company,
            fromDate: ($from_date !== null && $from_date !== '') ? Carbon::parse($from_date)->startOfDay() : null,
            toDate: ($to_date !== null && $to_date !== '') ? Carbon::parse($to_date)->endOfDay() : null,
            activity: OrgActivityFilterEnum::tryFrom($activity ?? 'all') ?? OrgActivityFilterEnum::ALL,
            minCount: $min_count,
            maxCount: $max_count,
            topN: $top_n,
            orderBy: OrgActivityOrderEnum::tryFrom($order_by ?? 'count_desc') ?? OrgActivityOrderEnum::COUNT_DESC,
        )->map(fn ($row) => $row->toArray())->all();

        return ['count' => count($rows), 'organizations' => $rows];
    }
}
