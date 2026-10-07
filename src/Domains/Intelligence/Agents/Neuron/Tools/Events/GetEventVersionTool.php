<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Events;

use Kanvas\Event\Events\Models\EventVersionDate;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesEventVersionForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Full detail of one event VERSION (a scheduled edition of an event): dates, price, capacity,
 * attendee count, agenda and status. Company-scoped. A version id comes from get_event, the
 * calendar, or a report.
 */
#[AgentTool(name: 'Get Event Version', category: 'events')]
class GetEventVersionTool extends Tool
{
    use HasKanvasContext;
    use ResolvesEventVersionForTool;
    use TrackByInputs;

    protected string $name = 'get_event_version';

    protected ?string $description = 'Full detail of one event version (edition) by version_id: dates, price per ticket, capacity, '
        . 'attendee count, agenda, status and the parent event. Use for "details of this edition" or when a '
        . 'report/calendar gave you a version id.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'version_id',
                type: PropertyType::INTEGER,
                description: 'The id of the event version to read.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $version_id): array
    {
        $version = $this->resolveEventVersionOrError($version_id);

        if (is_array($version)) {
            return $version;
        }

        $version->load(['event', 'eventStatus', 'currency', 'dates']);

        return [
            'version_id' => $version->getId(),
            'name' => $version->name,
            'version' => $version->version,
            'classification' => $version->classification,
            'description' => $version->description,
            'event' => $version->event ? ['id' => $version->event->getId(), 'name' => $version->event->name] : null,
            'status' => $version->eventStatus?->name,
            'price_per_ticket' => (float) $version->price_per_ticket,
            'currency' => $version->currency?->code,
            'max_capacity' => $version->getMaxCapacity(),
            'total_attendees' => (int) $version->total_attendees,
            'start_at' => $version->start_at?->toDateTimeString(),
            'end_at' => $version->end_at?->toDateTimeString(),
            'agenda' => $version->agenda,
            'dates' => $version->dates->map(fn (EventVersionDate $d): array => [
                'date' => $d->event_date?->toDateString(),
                'start_time' => $d->start_time,
                'end_time' => $d->end_time,
            ])->all(),
        ];
    }
}
