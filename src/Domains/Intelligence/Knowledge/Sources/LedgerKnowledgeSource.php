<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Sources;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Knowledge\Contracts\KnowledgeSource;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeDocument;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Override;

/**
 * Two kinds of ledger event become company memory. A saved memory (`agent.knowledge.saved`, written
 * by the `remember` tool) is kept as the agent wrote it, forever. An allowlisted outcome (a plan
 * approved, a task completed, a lead's status changed, a message sent) becomes a one-line record of
 * what was done, pruned by retention like a conversation. Ordinary telemetry is never embedded.
 */
final class LedgerKnowledgeSource implements KnowledgeSource
{
    public const string MEMORY_EVENT = 'agent.knowledge.saved';

    public const string MEMORY_SOURCE_TYPE = 'memory';

    public const string OUTCOME_SOURCE_TYPE = 'ledger';

    /**
     * Exact names the ledger emits (ApprovePlanAction, UpdateTaskStatusAction, LeadObserver, the
     * follow-up engine); a name that nothing emits is a silent gap in memory, not a safe default.
     */
    private const array OUTCOME_EVENTS = [
        'plan.approved',
        'plan.task.completed',
        'lead.stage.changed',
        'lead.follow_up.sent',
    ];

    private const array OUTCOME_PREFIXES = ['agent.decided'];

    private const array OUTCOME_TEXT_KEYS = ['summary', 'title', 'subject', 'name', 'message', 'content', 'description', 'reason'];

    private const int OUTCOME_MAX_CHARS = 500;

    public static function wants(string $eventType): bool
    {
        return $eventType === self::MEMORY_EVENT
            || in_array($eventType, self::OUTCOME_EVENTS, true)
            || Str::startsWith($eventType, self::OUTCOME_PREFIXES);
    }

    /**
     * The same allowlist as wants(), applied in SQL for a sweep over the ledger.
     *
     * @param Builder<Event> $query
     * @return Builder<Event>
     */
    public static function whereWanted(Builder $query): Builder
    {
        return $query->where(static function (Builder $wanted): void {
            $wanted->whereIn('event_type', [self::MEMORY_EVENT, ...self::OUTCOME_EVENTS]);

            foreach (self::OUTCOME_PREFIXES as $prefix) {
                $wanted->orWhere('event_type', 'like', $prefix . '%');
            }
        });
    }

    #[Override]
    public function entityType(): string
    {
        return Event::class;
    }

    #[Override]
    public function find(int $entityId, int $appId, int $companyId): ?Model
    {
        return Event::query()
            ->whereKey($entityId)
            ->where('apps_id', $appId)
            ->where('companies_id', $companyId)
            ->first();
    }

    #[Override]
    public function isEnabledFor(Apps $app): bool
    {
        return KnowledgeComponents::memoryEnabled($app);
    }

    #[Override]
    public function build(Model $entity): array
    {
        if (! $entity instanceof Event) {
            throw new InvalidArgumentException('LedgerKnowledgeSource only supports Event entities.');
        }

        if (! self::wants($entity->event_type)) {
            return [];
        }

        $payload = is_array($entity->payload) ? $entity->payload : [];
        $isMemory = $entity->event_type === self::MEMORY_EVENT;
        $content = $isMemory ? self::memoryText($payload) : self::outcomeText($entity->event_type, $payload);

        if ($content === '') {
            return [];
        }

        $kind = $isMemory ? self::MEMORY_SOURCE_TYPE : self::OUTCOME_SOURCE_TYPE;

        return [
            new KnowledgeDocument(
                id: implode('-', ['ledger', $kind, $entity->getId()]),
                content: $content,
                metadata: [
                    'apps_id' => $entity->apps_id,
                    'companies_id' => $entity->companies_id,
                    'entity_type' => (string) ($entity->source_entity_type ?? ''),
                    'entity_id' => (int) ($entity->source_entity_id ?? 0),
                    'source_type' => $kind,
                    'source_id' => (string) $entity->getId(),
                    'agent_id' => $entity->actor_type === 'Agent' ? (int) $entity->actor_id : 0,
                    'users_id' => in_array($entity->actor_type, ['Users', 'User'], true) ? (int) $entity->actor_id : 0,
                    'created_at' => $entity->occurred_at?->timestamp ?? 0,
                ],
            ),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function memoryText(array $payload): string
    {
        $tags = is_array($payload['tags'] ?? null) ? implode(', ', array_filter($payload['tags'], is_string(...))) : '';
        $text = trim((string) ($payload['title'] ?? '') . "\n" . (string) ($payload['content'] ?? ''));

        return $text === '' ? '' : ($tags === '' ? $text : "{$text}\nTags: {$tags}");
    }

    /**
     * The subject and the first line only: an outcome is a fact about what happened, not the payload.
     *
     * @param array<string, mixed> $payload
     */
    private static function outcomeText(string $eventType, array $payload): string
    {
        foreach (self::OUTCOME_TEXT_KEYS as $key) {
            $value = $payload[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $line = trim(strtok(trim($value), "\n") ?: '');

            return mb_substr("{$eventType}: {$line}", 0, self::OUTCOME_MAX_CHARS);
        }

        return '';
    }
}
