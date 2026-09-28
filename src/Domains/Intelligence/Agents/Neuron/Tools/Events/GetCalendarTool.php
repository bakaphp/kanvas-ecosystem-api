<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Events;

use Illuminate\Support\Carbon;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionDate;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Event editions scheduled within a date range, for a calendar / agenda view. The date filter is
 * pushed into SQL (via the versions' dates), so it returns exactly what falls in the window.
 * Company-scoped.
 */
#[AgentTool(name: 'Get Calendar', category: 'events')]
class GetCalendarTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'get_calendar',
            description: 'Event editions scheduled between two dates, for a calendar/agenda view. Use for "what\'s on '
                . 'the calendar", "events this week/month", "what\'s coming up". Pass an ISO date range (from/to); '
                . 'resolve natural phrases like "this week" to dates before calling.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'from', type: PropertyType::STRING, description: 'Range start as an ISO date (YYYY-MM-DD).', required: true),
            new ToolProperty(name: 'to', type: PropertyType::STRING, description: 'Range end as an ISO date (YYYY-MM-DD).', required: true),
            new ToolProperty(name: 'limit', type: PropertyType::INTEGER, description: 'Max editions to return. Defaults to 100, max 300.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $from, string $to, ?int $limit = null): array
    {
        if (trim($from) === '' || trim($to) === '') {
            return ['error' => 'Provide both from and to dates (YYYY-MM-DD).'];
        }

        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();
        $limit = max(1, min(300, $limit ?? 100));

        $versions = EventVersion::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->whereHas(
                'dates',
                fn ($q) => $q->notDeleted()->whereBetween('event_date', [$start, $end]),
            )
            ->with([
                'event',
                'eventStatus',
                'dates' => fn ($q) => $q->notDeleted()->whereBetween('event_date', [$start, $end]),
            ])
            ->limit($limit)
            ->get();

        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'count' => $versions->count(),
            'editions' => $versions->map(fn (EventVersion $v): array => [
                'version_id' => $v->getId(),
                'event_name' => $v->event?->name ?? $v->name,
                'status' => $v->eventStatus?->name,
                'dates' => $v->dates->map(fn (EventVersionDate $d): array => [
                    'date' => $d->event_date?->toDateString(),
                    'start_time' => $d->start_time,
                    'end_time' => $d->end_time,
                ])->all(),
            ])->all(),
        ];
    }
}
