<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Events;

use Illuminate\Support\Carbon;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Search and list events (name, dates, taxonomy, status, how many versions). The read entry point
 * for the Events domain — the agent uses this to find an event before pulling its detail with
 * get_event. Company-scoped.
 */
#[AgentTool(name: 'List Events', category: 'events')]
class ListEventsTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'list_events',
            description: 'Search and list events with their name, dates, type/category/class/status and how many '
                . 'versions (editions) each has. Use for "what events do I have", "find the leadership event", '
                . '"list my events". Pass search to filter by name/description; omit for the most recent. For one '
                . 'event\'s full detail use get_event.',
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
                name: 'search',
                type: PropertyType::STRING,
                description: 'Free-text to match against event name/description. Omit to list all.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Max events to return, most recent first. Defaults to 50, max 100.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $search = null, ?int $limit = null): array
    {
        $limit = max(1, min(100, $limit ?? 50));
        $search = $search !== null ? trim($search) : null;

        $events = Event::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->when($search !== null && $search !== '', function ($q) use ($search): void {
                $q->where(fn ($w) => $w->where('name', 'like', '%' . $search . '%')
                    ->orWhere('description', 'like', '%' . $search . '%'));
            })
            ->with(['eventType', 'eventCategory', 'eventClass', 'eventStatus'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return [
            'count' => $events->count(),
            'events' => $events->map(fn (Event $event): array => [
                'id' => $event->getId(),
                'name' => $event->name,
                'start_at' => $event->start_at !== null ? Carbon::parse((string) $event->start_at)->toDateTimeString() : null,
                'end_at' => $event->end_at !== null ? Carbon::parse((string) $event->end_at)->toDateTimeString() : null,
                'versions_count' => (int) $event->versions_count,
                'type' => $event->eventType?->name,
                'category' => $event->eventCategory?->name,
                'class' => $event->eventClass?->name,
                'status' => $event->eventStatus?->name,
                'created_at' => $event->created_at?->toDateString(),
            ])->all(),
        ];
    }
}
