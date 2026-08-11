<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Sources;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Kanvas\Intelligence\Knowledge\Contracts\KnowledgeSource;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeDocument;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Override;

/**
 * DEFERRED — scaffold only, intentionally NOT wired live yet.
 *
 * Turns an `agent.knowledge.saved` ledger event (written by the `remember` tool) into a RAG document
 * so saved memories become semantically searchable. It is NOT yet activated: the remember tool only
 * emits + preserves the event today; we want to see that working in production before we start
 * embedding memories into the vector store.
 *
 * To activate later:
 *   1. Uncomment the event-type filter in build() (so a broad Event trigger only indexes memories).
 *   2. Register this source in KnowledgeSourceRegistry.
 *   3. Dispatch KnowledgeIndexRequested::fromModel($event) from RememberKnowledgeTool after the append.
 */
final class LedgerKnowledgeSource implements KnowledgeSource
{
    #[Override]
    public function entityType(): string
    {
        return Event::class;
    }

    #[Override]
    public function build(Model $entity): array
    {
        if (! $entity instanceof Event) {
            throw new InvalidArgumentException('LedgerKnowledgeSource only supports Event entities.');
        }

        // Only memory events belong in the knowledge store — ordinary telemetry must not be embedded.
        // Kept commented until we activate indexing; see the class docblock.
        // if ($entity->event_type !== 'agent.knowledge.saved') {
        //     return [];
        // }

        $payload = is_array($entity->payload) ? $entity->payload : [];
        $content = trim((string) ($payload['title'] ?? '') . "\n" . (string) ($payload['content'] ?? ''));
        if ($content === '') {
            return [];
        }

        $tags = is_array($payload['tags'] ?? null) ? $payload['tags'] : [];

        return [
            new KnowledgeDocument(
                id: implode('-', ['ledger', 'memory', $entity->getId()]),
                content: $content,
                metadata: [
                    'apps_id' => $entity->apps_id,
                    'companies_id' => $entity->companies_id,
                    'entity_type' => $entity::class,
                    'entity_id' => $entity->getId(),
                    'source_type' => 'memory',
                    'source_id' => (string) $entity->getId(),
                    'actor_type' => $entity->actor_type,
                    'actor_id' => $entity->actor_id,
                    'tags' => implode(',', $tags),
                    'created_at' => $entity->occurred_at?->timestamp ?? 0,
                ],
            ),
        ];
    }
}
