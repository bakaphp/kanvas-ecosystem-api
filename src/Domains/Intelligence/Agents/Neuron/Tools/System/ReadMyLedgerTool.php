<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\System;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Ledger\Models\Event;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Read My Ledger')]
class ReadMyLedgerTool extends Tool
{
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Agent $agent,
    ) {
        parent::__construct(
            name: 'read_my_ledger',
            description: 'Recall your own recent actions from the nervous-system ledger for this company — both events you emitted directly and '
                . 'the Kanvas records you created as your own user. Use it to avoid repeating work (e.g. do not re-contact someone you already '
                . 'handled) and to ground yourself before acting.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'event_type_prefix',
                type: PropertyType::STRING,
                description: 'Optional filter — only events whose type starts with this (e.g. "engagement." or "crm.").',
                required: false,
            ),
            new ToolProperty(
                name: 'since_hours',
                type: PropertyType::INTEGER,
                description: 'Only events within the last N hours. Default 168 (7 days).',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Maximum number of events to return. Default 50, capped at 200.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $event_type_prefix = null,
        ?int $since_hours = null,
        ?int $limit = null,
    ): array {
        $since = Carbon::now()->subHours($since_hours ?? 168);
        $cap = max(1, min($limit ?? 50, 200));

        $query = Event::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->where(fn (Builder $identity) => $this->scopeToAgentIdentity($identity))
            ->where('occurred_at', '>=', $since)
            ->recent()
            ->limit($cap);

        if ($event_type_prefix !== null && $event_type_prefix !== '') {
            $query->where('event_type', 'like', $event_type_prefix . '%');
        }

        $events = $query->get()->map(static fn (Event $event): array => [
            'event_type' => $event->event_type,
            'status' => $event->status,
            'occurred_at' => $event->occurred_at->toIso8601String(),
            'source_entity_type' => $event->source_entity_type,
            'source_entity_id' => $event->source_entity_id,
            'payload' => $event->payload,
        ])->all();

        return [
            'count' => count($events),
            'events' => $events,
        ];
    }

    /**
     * Match everything this agent did: events it emitted as its Agent identity, plus
     * events its own Kanvas records raise as its user (EmitsLedgerEventsForEntity
     * resolves users_id → actor_type=User). The user branch is added only when the
     * agent has a DEDICATED user — never the shared company AI user, which would pull
     * in every other agent's work.
     */
    private function scopeToAgentIdentity(Builder $query): void
    {
        $query->where(function (Builder $q): void {
            $q->where('actor_type', 'Agent')->where('actor_id', $this->agent->getId());
        });

        $agentUserId = $this->agent->user_id;
        $sharedAiUserId = $this->company->getAiAgentUser()?->getId();

        if ($agentUserId !== null && $agentUserId !== $sharedAiUserId) {
            $query->orWhere(function (Builder $q) use ($agentUserId): void {
                $q->where('actor_type', 'User')->where('actor_id', $agentUserId);
            });
        }
    }
}
