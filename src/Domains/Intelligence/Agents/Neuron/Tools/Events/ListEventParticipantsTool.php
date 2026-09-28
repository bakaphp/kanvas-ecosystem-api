<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Events;

use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionParticipant;
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
 * Lists who is registered in an event version, with participant type, ticket price, discount and
 * payment status. Resolves the version tenant-scoped first (participant rows carry no apps/company
 * columns, so scoping goes through the parent version). Company-scoped.
 */
#[AgentTool(name: 'List Event Participants', category: 'events')]
class ListEventParticipantsTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'list_event_participants',
            description: 'Lists who is registered in an event version (edition), with participant type, ticket price, '
                . 'discount and payment status. Use for "who\'s enrolled", "show the attendee list". Identify the '
                . 'edition by version_id (from get_event / get_calendar).',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'version_id', type: PropertyType::INTEGER, description: 'The event version id.', required: true),
            new ToolProperty(name: 'limit', type: PropertyType::INTEGER, description: 'Max participants to return. Defaults to 50, max 200.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $version_id, ?int $limit = null): array
    {
        $limit = max(1, min(200, $limit ?? 50));

        try {
            /** @var EventVersion $version */
            $version = EventVersion::getByIdFromCompanyApp($version_id, $this->company, $this->app);
        } catch (Throwable) {
            return ['error' => sprintf('No event version #%d found in this company.', $version_id)];
        }

        $total = (int) EventVersionParticipant::query()
            ->where('event_version_id', $version->getId())
            ->notDeleted()
            ->count();

        $participants = EventVersionParticipant::query()
            ->where('event_version_id', $version->getId())
            ->notDeleted()
            ->with(['participant.people', 'participantType'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return [
            'version_id' => $version->getId(),
            'count' => $total,
            'returned' => $participants->count(),
            'participants' => $participants->map(fn (EventVersionParticipant $p): array => [
                'name' => $p->participant?->people?->getName(),
                'participant_type' => $p->participantType?->name,
                'ticket_price' => (float) $p->ticket_price,
                'discount' => (float) $p->discount,
                'payment_status' => $p->payment_status,
            ])->all(),
        ];
    }
}
