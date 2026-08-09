<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\RAG\Retrieval;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeEntity;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeScope;
use Kanvas\Intelligence\Knowledge\Enums\KnowledgeConfigurationEnum;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use Override;

/**
 * Native Neuron retrieval over the shared knowledge store. Always pulls the
 * company-wide knowledge base (entity_id = 0) — shared by every agent — and,
 * when a record is in scope (a Lead), additionally its own prospect-isolated
 * knowledge. Gated by the app's knowledge-enabled flag so it no-ops (zero
 * embedding/Typesense cost) for apps that don't use knowledge.
 */
class KnowledgeRetrieval implements RetrievalInterface
{
    public function __construct(
        private readonly ?Apps $app,
        private readonly ?Companies $company,
        private readonly ?Model $entity = null,
    ) {
    }

    /** @return list<Document> */
    #[Override]
    public function retrieve(Message $query): array
    {
        if ($this->app === null || $this->company === null) {
            return [];
        }

        if (! filter_var($this->app->get(KnowledgeConfigurationEnum::ENABLED->value), FILTER_VALIDATE_BOOL)) {
            return [];
        }

        $store = KnowledgeComponents::store($this->app);
        $topK = KnowledgeComponents::resultLimit($this->app);
        $minScore = KnowledgeComponents::minScore($this->app);
        $embedding = KnowledgeComponents::embedder($this->app)->embed((string) $query->getContent());

        $hits = $store->search(
            $embedding,
            KnowledgeScope::forTenant($this->app->getId(), $this->company->getId()),
            $topK,
            $minScore,
        );

        // Add the record-in-scope's own (prospect-isolated) knowledge — but only
        // when it forms a valid tenant knowledge entity. A global row like a Users
        // (apps_id/companies_id = 0) has no indexed knowledge and can't be scoped,
        // so we skip it and return company-wide docs only.
        if ($this->entity !== null) {
            try {
                $entityScope = KnowledgeScope::fromEntity(KnowledgeEntity::fromModel($this->entity));
                $hits = array_merge($hits, $store->search($embedding, $entityScope, $topK, $minScore));
            } catch (InvalidArgumentException) {
                // not a tenant-scoped knowledge entity — company-wide only
            }
        }

        return $this->toDocuments($hits, $topK);
    }

    /**
     * @param array<int, array{content: string, sourceType: string, sourceName: string, score: float, metadata: array<string, mixed>}> $hits
     * @return list<Document>
     */
    private function toDocuments(array $hits, int $topK): array
    {
        usort($hits, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $seen = [];
        $documents = [];

        foreach ($hits as $hit) {
            // Dedup by content so the same passage (company + entity scope, or
            // re-indexed under a different source) never lands in context twice.
            $key = md5($hit['content']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $document = new Document($hit['content']);
            $document->sourceType = $hit['sourceType'];
            $document->sourceName = $hit['sourceName'];
            $document->setScore($hit['score']);
            $documents[] = $document;

            if (count($documents) >= $topK) {
                break;
            }
        }

        return $documents;
    }
}
