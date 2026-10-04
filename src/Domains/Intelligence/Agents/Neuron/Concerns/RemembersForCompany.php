<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Concerns;

use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Contracts\ConversesWithCustomer;
use Kanvas\Intelligence\Agents\Neuron\Memory\ConversationMemoryNode;
use Kanvas\Intelligence\Agents\Neuron\RAG\Services\RagComponents;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use Kanvas\Intelligence\Knowledge\Sources\LedgerKnowledgeSource;
use NeuronAI\Agent\Nodes\AgentEndNode;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Workflow\Node;
use Override;

/**
 * Company memory: writing the turn that just ended and deciding what a turn may read back. Requires
 * the HasKanvasAgentBehavior properties and its requireAgent()/requestingHuman().
 */
trait RemembersForCompany
{
    /**
     * Whether this agent type writes its turns to company memory and reads them back. Off by default;
     * internal teammates recall company-wide, a customer-facing agent recalls only the record it is on
     * (recordMemoryScope), so one prospect's conversation never reaches another.
     */
    protected function remembersForCompany(): bool
    {
        return false;
    }

    /**
     * What this turn may read back from company memory, decided by audience. A customer-facing agent
     * recalls only the record it is on (recordMemoryScope). An internal agent recalls the raw
     * conversations its own human had, plus everything the company deliberately kept: saved memories
     * and what the agents did. A chat Jenn had with another agent is hers; a fact she asked an agent to
     * remember, or a plan it approved, is the company's. Null means no recall on this turn.
     */
    protected function recallMemoryScope(): ?FilterExpression
    {
        if ($this instanceof ConversesWithCustomer) {
            return $this->recordMemoryScope();
        }

        $shared = Filter::in('source_type', [LedgerKnowledgeSource::MEMORY_SOURCE_TYPE, LedgerKnowledgeSource::OUTCOME_SOURCE_TYPE]);
        $human = $this->requestingHuman()?->getId();

        return $human === null ? $shared : FilterGroup::or(Filter::eq('users_id', $human), $shared);
    }

    /**
     * The record a customer-facing agent may recall: the entity in scope and, for a Lead, its person,
     * which is what every channel session of that prospect is keyed to. Null means no record, so no
     * recall at all on a customer surface.
     */
    protected function recordMemoryScope(): ?FilterExpression
    {
        $entity = $this->resolveEntityForTurn();

        if ($entity === null) {
            return null;
        }

        $records = [FilterGroup::and(Filter::eq('entity_type', $entity::class), Filter::eq('entity_id', (int) $entity->getKey()))];

        if ($entity instanceof Lead && $entity->people_id) {
            $records[] = FilterGroup::and(Filter::eq('entity_type', People::class), Filter::eq('entity_id', (int) $entity->people_id));
        }

        return FilterGroup::or(...$records);
    }

    private function requireApp(): Apps
    {
        if ($this->app === null) {
            throw new ValidationException('App not set. Call setConfiguration() before using company memory.');
        }

        return $this->app;
    }

    protected function companyMemoryEnabled(): bool
    {
        return $this->app !== null && KnowledgeComponents::memoryEnabled($this->app);
    }

    /**
     * Instruction lines for an agent that recalls: without them the model treats the recalled entries
     * as hints and re-reads the ledger it was just handed, one full inference round per reflex.
     *
     * @return list<string>
     */
    protected function memoryRecallLines(): array
    {
        if (! $this->companyMemoryActive()) {
            return [];
        }

        return [
            'Recalled context reaches you in the EXTRA-CONTEXT block as "Earlier conversation", "Saved '
                . 'memory" and "Ledger" entries, each dated. For a question about what was discussed, decided '
                . 'or done before, answer from those entries; call read_my_ledger or search again only when '
                . 'they do not cover it.',
        ];
    }

    protected function companyMemoryActive(): bool
    {
        return $this->remembersForCompany()
            && $this->app !== null
            && $this->company !== null
            && $this->agent !== null
            && $this->companyMemoryEnabled();
    }

    protected function companyMemoryStore(): VectorStoreInterface
    {
        return KnowledgeComponents::memoryStore($this->requireApp());
    }

    protected function companyMemoryEmbeddings(): EmbeddingsProviderInterface
    {
        return RagComponents::embeddings($this->requireApp());
    }

    /**
     * A private turn (an injected wake instruction) is never ingested: nobody said it.
     *
     * @return Node[]
     */
    #[Override]
    protected function exitNodes(): array
    {
        if ($this->privateUserTurn || ! $this->companyMemoryActive()) {
            return [new AgentEndNode()];
        }

        return [new ConversationMemoryNode(
            $this->companyMemoryStore(),
            $this->companyMemoryEmbeddings(),
            $this->companyMemoryMetadata(),
            KnowledgeComponents::memoryIngestMinChars($this->requireApp()),
        )];
    }

    /**
     * @return array<string, int|string>
     */
    protected function companyMemoryMetadata(): array
    {
        $entity = $this->resolveEntityForTurn();

        return ConversationMemoryNode::metadata(
            appId: $this->requireApp()->getId(),
            companyId: (int) $this->company?->getId(),
            agentId: $this->requireAgent()->getId(),
            usersId: (int) $this->requestingHuman()?->getId(),
            entityType: $entity === null ? null : $entity::class,
            entityId: $entity === null ? null : (int) $entity->getKey(),
        );
    }
}
