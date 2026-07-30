<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\System;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Users\Models\UsersAssociatedApps;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * What a teammate has done ON THE RECORD in scope — their ledger actions filtered to this exact
 * entity (lead/order/…). Deliberately entity-scoped: an agent can tell you what someone did on the
 * record you're both looking at, not surveil their whole activity across the company.
 */
#[AgentTool(name: 'Read User Activity', category: 'ecosystem')]
class ReadUserActivityTool extends Tool
{
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly ?Model $subject = null,
    ) {
        parent::__construct(
            name: 'read_user_activity',
            description: 'See what a specific teammate has done ON THE RECORD you are currently working on (this lead/order/etc.), '
                . 'from the nervous-system ledger — scoped to this record only. Identify them by user_id or @handle. '
                . 'Use it to answer "what has <teammate> done on this?".',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'user_id',
                type: PropertyType::INTEGER,
                description: 'The id of the teammate whose activity on this record to read.',
                required: false,
            ),
            new ToolProperty(
                name: 'handle',
                type: PropertyType::STRING,
                description: 'The @displayname/handle of the teammate (alternative to user_id).',
                required: false,
            ),
            new ToolProperty(
                name: 'since_hours',
                type: PropertyType::INTEGER,
                description: 'Only actions within the last N hours. Default 720 (30 days).',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Maximum number of actions to return. Default 50, capped at 200.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?int $user_id = null,
        ?string $handle = null,
        ?int $since_hours = null,
        ?int $limit = null,
    ): array {
        if ($this->subject === null) {
            return [
                'status' => 'error',
                'message' => 'There is no record in scope for this conversation.',
            ];
        }

        $actorId = $this->resolveUserId($user_id, $handle);

        if ($actorId === null) {
            return [
                'status' => 'error',
                'message' => 'Could not identify the teammate. Pass a valid user_id or @displayname/handle.',
            ];
        }

        $since = Carbon::now()->subHours($since_hours ?? 720);
        $cap = max(1, min($limit ?? 50, 200));

        $events = Event::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->where('actor_type', 'User')
            ->where('actor_id', $actorId)
            ->where('source_entity_type', $this->subject::class)
            ->where('source_entity_id', $this->subject->getKey())
            ->where('occurred_at', '>=', $since)
            ->recent()
            ->limit($cap)
            ->get()
            ->map(static fn (Event $event): array => [
                'event_type' => $event->event_type,
                'status' => $event->status,
                'occurred_at' => $event->occurred_at->toIso8601String(),
                'payload' => $event->payload,
            ])->all();

        return [
            'record' => class_basename($this->subject) . ' #' . $this->subject->getKey(),
            'user_id' => $actorId,
            'count' => count($events),
            'events' => $events,
        ];
    }

    /**
     * No tenant guard needed — the ledger query is already bound to this app, company and record,
     * so a foreign id simply returns no events.
     */
    private function resolveUserId(?int $userId, ?string $handle): ?int
    {
        if ($userId !== null) {
            return $userId;
        }

        if ($handle === null) {
            return null;
        }

        $normalized = ltrim(trim($handle), '@');

        if ($normalized === '') {
            return null;
        }

        $association = UsersAssociatedApps::query()
            ->where('apps_id', $this->app->getId())
            ->where('displayname', $normalized)
            ->first();

        return $association !== null ? (int) $association->users_id : null;
    }
}
