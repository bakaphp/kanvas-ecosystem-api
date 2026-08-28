<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Tavily;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Kanvas\Connectors\Tavily\Client;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;
use Throwable;

#[AgentTool(name: 'Tavily Web Research', category: 'knowledge')]
class TavilyWebResearchTool implements KanvasToolInterface
{
    use HasKanvasContext;

    public function name(): string
    {
        return Str::slug(AgentTool::fromClass($this)?->name ?? class_basename($this), '_');
    }

    public function instructions(): string
    {
        $name = AgentTool::fromClass($this)?->name ?? $this->name();

        return "Use `{$name}` to search the web for information about a company or topic using Tavily. Pass a specific, detailed query including the company name and exactly what you need. Returns a synthesized answer with source snippets.";
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Search the web using Tavily. Returns a synthesized answer with source snippets. Requires Tavily API to be configured for this app.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) $request->string('query'));

        if ($query === '') {
            return (string) json_encode(['error' => 'The query was empty. Pass the question you want answered.']);
        }

        try {
            $response = new Client($this->app)->search(
                $query,
                ['max_results' => max(1, min($request->integer('max_results') ?: 5, 20))],
            );
        } catch (Throwable $e) {
            return (string) json_encode(['error' => $e->getMessage()]);
        }

        // Sources stay as discrete records rather than one concatenated blob: a model that can see
        // which claim came from which URL cites it, and one that gets a wall of text does not.
        return (string) json_encode([
            'answer' => $response['answer'] ?? null,
            'results' => array_map(
                fn (array $result): array => [
                    'title' => $result['title'] ?? '',
                    'url' => $result['url'] ?? '',
                    'content' => $result['content'] ?? '',
                ],
                $response['results'] ?? [],
            ),
        ]);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema
                ->string()
                ->description('Full research question or query. Be specific: include the company name and exactly what information you need (e.g. "real estate properties owned or leased by Saks Global, addresses and square footage").')
                ->required(),
            'max_results' => $schema
                ->integer()
                ->description('How many sources to return, 1-20. Defaults to 5.'),
        ];
    }
}
