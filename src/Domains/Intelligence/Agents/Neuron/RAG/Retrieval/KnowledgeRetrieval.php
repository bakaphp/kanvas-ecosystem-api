<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\RAG\Retrieval;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeScope;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use Kanvas\Intelligence\Knowledge\VectorStores\TypesenseKnowledgeStore;
use Kanvas\Intelligence\Knowledge\Workflows\IndexKnowledgeDocumentActivity;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use Override;

/**
 * Native Neuron retrieval over the shared knowledge store. Retrieves the agent's
 * own uploaded docs plus, for a customer-facing agent, the lead in scope — each
 * scoped to its entity so knowledge never leaks between prospects. Explicitly
 * internal agents can instead retrieve across their app/company boundary.
 * No-ops when the app hasn't enabled knowledge.
 */
class KnowledgeRetrieval implements RetrievalInterface
{
    public function __construct(
        private readonly ?Apps $app,
        private readonly ?Companies $company,
        private readonly ?Model $agent = null,
        private readonly ?Model $entity = null,
        private readonly bool $organizationWide = false,
    ) {
    }

    /**
     * Per-run Neuron filters are ignored: tenant and entity scope is enforced by KnowledgeScope.
     *
     * @return list<Document>
     */
    #[Override]
    public function retrieve(Message $query, ?FilterExpression $filters = null): array
    {
        if ($this->app === null || $this->company === null) {
            return [];
        }

        if (! KnowledgeComponents::knowledgeEnabled($this->app)) {
            return [];
        }

        $store = KnowledgeComponents::store($this->app);
        $topK = KnowledgeComponents::resultLimit($this->app);
        $minScore = KnowledgeComponents::minScore($this->app);
        $question = (string) $query->getContent();
        $embedding = KnowledgeComponents::embedder($this->app)->embed($question);

        if ($this->organizationWide) {
            $hits = $store->search(
                $embedding,
                KnowledgeScope::forOrganization($this->app->getId(), $this->company->getId()),
                $topK,
                $minScore,
            );

            return self::rank([], $hits, $topK, $question);
        }

        // The agent's own docs, then the record in scope (a Lead). A global row like
        // a Users (apps_id/companies_id = 0) can't form a KnowledgeEntity, so it's skipped.
        $byScope = [];
        foreach ([$this->agent, $this->entity] as $scopeEntity) {
            $byScope[] = $scopeEntity === null ? [] : $this->searchScoped(
                $store,
                $embedding,
                $scopeEntity,
                $topK,
                $minScore,
            );
        }

        return self::rank($byScope[0], $byScope[1], $topK, $question);
    }

    /**
     * @param array<int, float> $embedding
     * @return array<int, array{content: string, sourceType: string, sourceName: string, score: float, metadata: array<string, mixed>}>
     */
    private function searchScoped(
        TypesenseKnowledgeStore $store,
        array $embedding,
        Model $scopeEntity,
        int $topK,
        ?float $minScore,
    ): array {
        try {
            $scope = KnowledgeScope::forModel($scopeEntity);
        } catch (InvalidArgumentException) {
            return [];
        }

        return $store->search($embedding, $scope, $topK, $minScore);
    }

    /**
     * The agent's documents fill the slots first; the record's own rows take what is left. A record's
     * messages repeat the words of the question being asked, and are already the agent's history
     * through the rollup store, so ranked by score alone they crowd the documents out. A hit that is
     * the question itself is dropped: the inbound message is indexed before retrieval runs and would
     * otherwise be the best match for itself.
     *
     * @param array<int, array{content: string, sourceType: string, sourceName: string, score: float, metadata: array<string, mixed>}> $documentHits
     * @param array<int, array{content: string, sourceType: string, sourceName: string, score: float, metadata: array<string, mixed>}> $entityHits
     * @return list<Document>
     */
    public static function rank(
        array $documentHits,
        array $entityHits,
        int $topK,
        string $question,
    ): array {
        $question = self::normalize($question);
        $seen = [];
        $documents = [];

        foreach ([$documentHits, $entityHits] as $hits) {
            usort($hits, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

            foreach ($hits as $hit) {
                $key = md5($hit['content']);
                if (isset($seen[$key]) || self::normalize($hit['content']) === $question) {
                    continue;
                }
                $seen[$key] = true;

                $documents[] = self::labelled($hit);

                if (count($documents) >= $topK) {
                    return $documents;
                }
            }
        }

        return $documents;
    }

    /**
     * The same provenance tag memory hits carry: the model is told that a document is the company's
     * own material and a record row is this record's past, instead of a class name and a uuid.
     *
     * @param array{content: string, sourceType: string, sourceName: string, score: float, metadata: array<string, mixed>} $hit
     */
    private static function labelled(array $hit): Document
    {
        $createdAt = (int) ($hit['metadata']['created_at'] ?? 0);
        $label = $hit['sourceType'] === IndexKnowledgeDocumentActivity::SOURCE_TYPE
            ? 'Company document'
            : 'Record history' . ($createdAt > 0 ? ', ' . date('Y-m-d', $createdAt) : '');

        return new Document("[{$label}] " . $hit['content'])
            ->setSourceType($hit['sourceType'])
            ->setSourceName($hit['sourceName'])
            ->setScore($hit['score'])
            ->setMetadata($hit['metadata']);
    }

    private static function normalize(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $text) ?? $text));
    }
}
