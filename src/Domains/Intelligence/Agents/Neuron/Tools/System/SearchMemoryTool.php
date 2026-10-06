<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\System;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\RAG\Retrieval\CompanyMemoryRetrieval;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Memory on demand. The automatic recall runs once per turn with the person's raw message and a
 * handful of hits; this is the same store under the same audience scope, called when the agent decides
 * it needs something specific, with a query it wrote itself, a date window, and a bigger limit.
 */
#[AgentTool(name: 'Search Memory', category: 'ecosystem')]
class SearchMemoryTool extends Tool
{
    use TrackByInputs;

    private const int DEFAULT_LIMIT = 10;

    private const int MAX_LIMIT = 25;

    protected string $name = 'search_memory';

    protected ?string $description = 'Search your long-term memory: earlier conversations, saved memories and recorded '
        . 'outcomes for this company. Use it when someone refers to something discussed or decided '
        . 'before that is not in the context you were given, or asks about a period ("last month", "in '
        . 'September"). Write the query as the topic itself ("Acme invoicing terms"), not the question, '
        . 'and narrow with since/until for a period. Each hit says when it happened.';

    public function __construct(
        private readonly VectorStoreInterface $store,
        private readonly EmbeddingsProviderInterface $embeddings,
        private readonly int $appId,
        private readonly int $companyId,
        private readonly ?FilterExpression $recallScope,
    ) {
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'query',
                type: PropertyType::STRING,
                description: 'What to look for, phrased as the topic (names, ids, terms), not as a question.',
                required: true,
            ),
            new ToolProperty(
                name: 'since',
                type: PropertyType::STRING,
                description: 'Optional start of the period, e.g. "2026-09-01" or "30 days ago".',
                required: false,
            ),
            new ToolProperty(
                name: 'until',
                type: PropertyType::STRING,
                description: 'Optional end of the period, e.g. "2026-09-30".',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'How many hits to return. Default 10, max 25.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $query,
        ?string $since = null,
        ?string $until = null,
        ?int $limit = null
    ): array {
        $query = trim($query);

        if ($query === '') {
            return self::miss('Give the query as a topic to search for.');
        }

        $filters = [CompanyMemoryRetrieval::scopeFor($this->appId, $this->companyId, $this->recallScope)];

        try {
            $sinceAt = $since === null ? null : Carbon::parse($since)->getTimestamp();
            $untilAt = $until === null ? null : Carbon::parse($until)->endOfDay()->getTimestamp();
        } catch (Throwable) {
            return self::miss('since/until must be dates, e.g. "2026-09-01". Do not retry with the same values.');
        }

        if ($sinceAt !== null) {
            $filters[] = Filter::gte('created_at', $sinceAt);
        }

        if ($untilAt !== null) {
            $filters[] = Filter::lte('created_at', $untilAt);
        }

        try {
            $hits = $this->store->search(new SearchRequest(
                embedding: $this->embeddings->embedText($query),
                filters: FilterGroup::and(...$filters),
                topK: max(1, min($limit ?? self::DEFAULT_LIMIT, self::MAX_LIMIT)),
            ));
        } catch (Throwable $e) {
            report($e);

            return self::miss('Memory is not reachable right now; answer from what you have.');
        }

        $memories = [];
        foreach ($hits as $hit) {
            $labelled = CompanyMemoryRetrieval::label($hit);
            $memories[] = [
                'when' => date('Y-m-d', (int) ($labelled->getMetadata()['created_at'] ?? 0)),
                'kind' => (string) ($labelled->getMetadata()['source_type'] ?? $labelled->getSourceType()),
                'content' => $labelled->getContent(),
            ];
        }

        if ($memories === []) {
            return self::miss('Nothing in memory matches; widen the period or rephrase the topic once, then answer from what you have.');
        }

        return ['count' => count($memories), 'memories' => $memories];
    }

    /**
     * @return array{count: int, memories: list<never>, message: string}
     */
    private static function miss(string $message): array
    {
        return ['count' => 0, 'memories' => [], 'message' => $message];
    }
}
