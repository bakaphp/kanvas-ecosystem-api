<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Knowledge;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Services\AgentTypesenseKnowledgeService;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;
use Throwable;

#[AgentTool(name: 'Knowledge Base Search', category: 'knowledge')]
class TypesenseKnowledgeSearchTool implements KanvasToolInterface
{
    use HasKanvasContext;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Search the tenant knowledge base (Typesense) for context relevant to the user question '
            . 'before answering. Returns the most relevant stored passages, most relevant first. '
            . 'Use this before answering questions about the tenant\'s own documents or policies.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) $request->string('query'));
        $limit = min(max($request->integer('limit', 4), 1), 10);

        if ($query === '') {
            return 'Provide a `query` to search the knowledge base.';
        }

        try {
            $service = new AgentTypesenseKnowledgeService($this->app);
            $hits = $service->search($service->embed($query), $limit);
        } catch (Throwable $e) {
            // Unreachable cluster / missing key / not-yet-created collection — degrade
            // to a clean message rather than throwing into the agent loop, but log it
            // so it isn't silently indistinguishable from a genuine empty result.
            Log::warning('TypesenseKnowledgeSearchTool search failed', [
                'app_id' => $this->app->getId(),
                'message' => $e->getMessage(),
            ]);

            return 'Knowledge base is not reachable right now.';
        }

        if ($hits === []) {
            return 'No relevant knowledge found.';
        }

        return collect($hits)->pluck('content')->implode("\n---\n");
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema
                ->string()
                ->description('The question or topic to search for.')
                ->required(),
            'limit' => $schema
                ->integer()
                ->description('Maximum number of results (1-10). Defaults to 4.')
                ->default(4),
        ];
    }
}
