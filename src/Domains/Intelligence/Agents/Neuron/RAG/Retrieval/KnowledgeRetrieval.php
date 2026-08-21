<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\RAG\Retrieval;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeScope;
use Kanvas\Intelligence\Knowledge\Enums\KnowledgeConfigurationEnum;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
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

        if ($this->organizationWide) {
            $hits = $store->search(
                $embedding,
                KnowledgeScope::forOrganization($this->app->getId(), $this->company->getId()),
                $topK,
                $minScore,
            );

            return $this->toDocuments($hits, $topK);
        }

        // The agent's own docs, plus the record in scope (a Lead). A global row like
        // a Users (apps_id/companies_id = 0) can't form a KnowledgeEntity, so it's skipped.
        $hits = [];
        foreach ([$this->agent, $this->entity] as $scopeEntity) {
            if ($scopeEntity === null) {
                continue;
            }

            try {
                $scope = KnowledgeScope::forModel($scopeEntity);
            } catch (InvalidArgumentException) {
                continue;
            }
            $hits = array_merge($hits, $store->search($embedding, $scope, $topK, $minScore));
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
