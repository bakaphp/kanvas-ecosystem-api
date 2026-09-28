<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Events;

use Kanvas\Event\Reports\Repositories\OpenEventsTrackingRepository;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * The event follow-up / tracking view: upcoming events and how their registration tracks against
 * goal (enrolled vs goal, % of goal). This is the read side of follow-up — which events are behind
 * and need a push. Company-scoped.
 */
#[AgentTool(name: 'Get Events Tracking', category: 'events')]
class GetEventsTrackingTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'get_events_tracking',
            description: 'Upcoming events and how their registration is tracking against goal — enrolled counts, goal, '
                . '% of goal, over the next N weeks. This is the follow-up / tracking view: which events are '
                . 'behind and need a push. Use for "which events are behind on registration", "how\'s enrollment '
                . 'looking", "follow-up on upcoming events".',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'weeks_ahead', type: PropertyType::INTEGER, description: 'How many weeks ahead to look. Defaults to 7.', required: false),
            new ToolProperty(name: 'search', type: PropertyType::STRING, description: 'Filter by event name.', required: false),
            new ToolProperty(name: 'event_type_id', type: PropertyType::INTEGER, description: 'Filter by event type id.', required: false),
            new ToolProperty(name: 'event_class_id', type: PropertyType::INTEGER, description: 'Filter by event class id.', required: false),
            new ToolProperty(name: 'event_category_id', type: PropertyType::INTEGER, description: 'Filter by event category id.', required: false),
            new ToolProperty(name: 'has_goal', type: PropertyType::BOOLEAN, description: 'When true, only events that have a registration goal set.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?int $weeks_ahead = null,
        ?string $search = null,
        ?int $event_type_id = null,
        ?int $event_class_id = null,
        ?int $event_category_id = null,
        ?bool $has_goal = null,
    ): array {
        $filters = ['weeks_ahead' => $weeks_ahead ?? 7];

        if ($search !== null && trim($search) !== '') {
            $filters['search'] = trim($search);
        }
        if ($event_type_id !== null) {
            $filters['event_type_id'] = $event_type_id;
        }
        if ($event_class_id !== null) {
            $filters['event_class_id'] = $event_class_id;
        }
        if ($event_category_id !== null) {
            $filters['event_category_id'] = $event_category_id;
        }
        if ($has_goal !== null) {
            $filters['has_goal'] = $has_goal;
        }

        $rows = OpenEventsTrackingRepository::forCompany($this->app, $this->company, $filters)
            ->map(fn ($row) => $row->toArray())
            ->all();

        return ['count' => count($rows), 'events' => $rows];
    }
}
