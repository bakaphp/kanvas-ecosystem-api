<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Events;

use Illuminate\Support\Carbon;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionDate;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Full detail of one event: taxonomy, tags, description, and its list of versions (editions) with
 * their dates, price and capacity. Company-scoped. Use get_event_version for one edition's full detail.
 */
#[AgentTool(name: 'Get Event', category: 'events')]
class GetEventTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'get_event',
            description: 'Full detail of one event by event_id: description, type/category/class/status, theme, tags, '
                . 'and its versions (editions) with dates, price and total attendees. Use once the user has picked or '
                . 'named a specific event (from list_events).',
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
                name: 'event_id',
                type: PropertyType::INTEGER,
                description: 'The id of the event to read.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $event_id): array
    {
        try {
            /** @var Event $event */
            $event = Event::getByIdFromCompanyApp($event_id, $this->company, $this->app);
        } catch (Throwable) {
            return ['error' => sprintf('No event #%d found in this company.', $event_id)];
        }

        $event->load([
            'eventType', 'eventCategory', 'eventClass', 'eventStatus', 'theme', 'themeArea', 'tags',
            'versions' => fn ($q) => $q->notDeleted()->with(['eventStatus', 'dates']),
        ]);

        return [
            'id' => $event->getId(),
            'name' => $event->name,
            'description' => $event->description,
            'start_at' => $event->start_at !== null ? Carbon::parse((string) $event->start_at)->toDateTimeString() : null,
            'end_at' => $event->end_at !== null ? Carbon::parse((string) $event->end_at)->toDateTimeString() : null,
            'type' => $event->eventType?->name,
            'category' => $event->eventCategory?->name,
            'class' => $event->eventClass?->name,
            'status' => $event->eventStatus?->name,
            'theme' => $event->theme?->name,
            'theme_area' => $event->themeArea?->name,
            'tags' => $event->tags->pluck('name')->all(),
            'versions_count' => (int) $event->versions_count,
            'versions' => $event->versions->map(fn (EventVersion $v): array => [
                'version_id' => $v->getId(),
                'name' => $v->name,
                'version' => $v->version,
                'status' => $v->eventStatus?->name,
                'price_per_ticket' => (float) $v->price_per_ticket,
                'max_capacity' => $v->getMaxCapacity(),
                'total_attendees' => (int) $v->total_attendees,
                'start_at' => $v->start_at?->toDateTimeString(),
                'end_at' => $v->end_at?->toDateTimeString(),
                'dates' => $v->dates->map(fn (EventVersionDate $d): array => [
                    'date' => $d->event_date?->toDateString(),
                    'start_time' => $d->start_time,
                    'end_time' => $d->end_time,
                ])->all(),
            ])->all(),
        ];
    }
}
