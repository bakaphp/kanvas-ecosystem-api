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
 * Native Neuron retrieval over the shared knowledge store: always the company-wide
 * base (entity_id = 0), plus the record's own knowledge when one is in scope.
 * No-ops when the app hasn't enabled knowledge.
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

        // Add the record's own (prospect-isolated) knowledge — but a global row like
        // a Users (apps_id/companies_id = 0) can't form a KnowledgeEntity, so skip it.
        if ($this->entity !== null) {
            try {
                $entityScope = KnowledgeScope::fromEntity(KnowledgeEntity::fromModel($this->entity));
                $hits = array_merge($hits, $store->search($embedding, $entityScope, $topK, $minScore));
            } catch (InvalidArgumentException) {
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
            // Dedup by content so the same passage never lands in context twice.
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
