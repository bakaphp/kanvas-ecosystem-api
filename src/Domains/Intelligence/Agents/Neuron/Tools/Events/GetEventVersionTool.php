<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Events;

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
 * Full detail of one event VERSION (a scheduled edition of an event): dates, price, capacity,
 * attendee count, agenda and status. Company-scoped. A version id comes from get_event, the
 * calendar, or a report.
 */
#[AgentTool(name: 'Get Event Version', category: 'events')]
class GetEventVersionTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'get_event_version',
            description: 'Full detail of one event version (edition) by version_id: dates, price per ticket, capacity, '
                . 'attendee count, agenda, status and the parent event. Use for "details of this edition" or when a '
                . 'report/calendar gave you a version id.',
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
        try {
            /** @var EventVersion $version */
            $version = EventVersion::getByIdFromCompanyApp($version_id, $this->company, $this->app);
        } catch (Throwable) {
            return ['error' => sprintf('No event version #%d found in this company.', $version_id)];
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
