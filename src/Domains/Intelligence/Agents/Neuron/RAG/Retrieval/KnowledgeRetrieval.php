<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\RAG\Retrieval;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeScope;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use Kanvas\Intelligence\Knowledge\VectorStores\TypesenseKnowledgeStore;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use Override;

/**
 * Native Neuron retrieval over the shared knowledge store. An agent's knowledge base is its own
 * uploaded documents plus the company's; another agent's files are never in it. The record in scope
 * (its cards: the lead, its company, organization and vehicles of interest) is passed only for a
 * customer-facing agent: an internal agent on a lead reads it through the lead tools. No-ops when the
 * app hasn't enabled knowledge.
 */
class KnowledgeRetrieval implements RetrievalInterface
{
    public function __construct(
        private readonly ?Apps $app,
        private readonly ?Companies $company,
        private readonly ?Model $agent = null,
        private readonly ?Model $entity = null,
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

        // A picture with no caption arrives as an empty turn with media; the embedder refuses a blank
        // string and there is nothing to search for anyway (KANVAS-ECOSYSTEM-6KC).
        $question = (string) $query->getContent();
        if (trim($question) === '') {
            return [];
        }

        return $this->retrieveFrom(
            KnowledgeComponents::store($this->app),
            KnowledgeComponents::embedder($this->app)->embed($question),
            $question,
        );
    }

    /**
     * Documents and record rows are searched apart, each in its own scope: searched together, a
     * company's lead rows repeat the words of the question and filled every slot, so the lot guide
     * (0.747) never made the top eight on "Shepard Select lot address" (Polly, agent 1292, 2026-11-05).
     *
     * @param array<int, float> $embedding
     * @return list<Document>
     */
    public function retrieveFrom(TypesenseKnowledgeStore $store, array $embedding, string $question): array
    {
        $topK = KnowledgeComponents::resultLimit($this->app);
        $minScore = KnowledgeComponents::minScore($this->app);

        $documentHits = [
            ...$this->searchDocuments(
                $store,
                $embedding,
                $this->scopeOf($this->agent),
                $topK,
                $minScore,
            ),
            ...$this->searchDocuments(
                $store,
                $embedding,
                KnowledgeScope::forTenant($this->app->getId(), $this->company->getId()),
                $topK,
                $minScore,
            ),
        ];

        $entityScope = $this->scopeOf($this->entity);
        $entityHits = $entityScope === null ? [] : $store->search(
            $embedding,
            $entityScope,
            $topK,
            $minScore,
        );

        return self::rank(
            $documentHits,
            $entityHits,
            $topK,
            $question,
        );
    }

    /** A global row like a Users (apps_id/companies_id = 0) can't form a KnowledgeEntity, so it has no scope. */
    private function scopeOf(?Model $model): ?KnowledgeScope
    {
        if ($model === null) {
            return null;
        }

        try {
            return KnowledgeScope::forModel($model);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param array<int, float> $embedding
     * @return array<int, array{content: string, sourceType: string, sourceName: string, score: float, metadata: array<string, mixed>}>
     */
    private function searchDocuments(
        TypesenseKnowledgeStore $store,
        array $embedding,
        ?KnowledgeScope $scope,
        int $topK,
        ?float $minScore,
    ): array {
        if ($scope === null) {
            return [];
        }

        return $store->searchByFilter(
            $embedding,
            $scope->documentFilter(),
            $topK,
            $minScore,
        );
    }

    /**
     * The agent's documents fill the slots first; the record's own rows take what is left. Ranked by
     * score alone, record rows that repeat the words of the question crowded the documents out. A hit
     * that is the question itself is dropped.
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
     * own material and a record row is this record's past, instead of a class name and a uuid. The
     * store row rides along as metadata minus the keys Neuron reserves on a Document (content,
     * embedding, score, ...): setMetadata() throws on those (KANVAS-ECOSYSTEM-6JX).
     *
     * @param array{content: string, sourceType: string, sourceName: string, score: float, metadata: array<string, mixed>} $hit
     */
    private static function labelled(array $hit): Document
    {
        $createdAt = (int) ($hit['metadata']['created_at'] ?? 0);
        $label = KnowledgeScope::isDocument($hit['sourceType'])
            ? 'Company document'
            : 'Record history' . ($createdAt > 0 ? ', ' . date('Y-m-d', $createdAt) : '');

        return new Document("[{$label}] " . $hit['content'])
            ->setSourceType($hit['sourceType'])
            ->setSourceName($hit['sourceName'])
            ->setScore($hit['score'])
            ->setMetadata(array_diff_key($hit['metadata'], array_flip(DocumentSchema::RESERVED_FIELDS)));
    }

    private static function normalize(string $text): string
    {
        return Str::lower(Str::squish($text));
    }
}
