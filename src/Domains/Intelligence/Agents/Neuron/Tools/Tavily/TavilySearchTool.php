<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Tavily;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesTavilyClientForTool;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Keyed by inputs: a research turn legitimately runs many distinct queries, and the default per-name
 * budget of 10 would abort the whole turn on the eleventh.
 */
#[AgentTool(name: 'Tavily Search', category: 'knowledge')]
class TavilySearchTool extends Tool implements HasRunKey
{
    use ResolvesTavilyClientForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'tavily_search',
            description: 'Search the live web and get back a short synthesized answer plus the sources it '
                . 'came from. Use it for anything outside Kanvas data — company research, market and '
                . 'competitor questions, news, prices, public facts, or checking something you are not '
                . 'sure is still true. Write a specific query naming the company or subject and exactly '
                . 'what you need, not a bare keyword. You get snippets per source, not whole pages — when '
                . 'you need the complete text of a page, use the URL-reading tool instead.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'query',
                type: PropertyType::STRING,
                description: 'The full research question. Be specific: include the company or subject name '
                    . 'and exactly what you need, e.g. "Saks Global real estate holdings, addresses and '
                    . 'square footage".',
                required: true,
            ),
            new ToolProperty(
                name: 'topic',
                type: PropertyType::STRING,
                description: 'Which index to search. Use "news" for current events and "finance" for '
                    . 'markets, tickers and filings — both rank recency far higher than "general".',
                required: false,
                enum: ['general', 'news', 'finance'],
            ),
            new ToolProperty(
                name: 'time_range',
                type: PropertyType::STRING,
                description: 'Only return results published within this window. Leave it out unless the '
                    . 'question is explicitly about recent events.',
                required: false,
                enum: ['day', 'week', 'month', 'year'],
            ),
            new ToolProperty(
                name: 'max_results',
                type: PropertyType::INTEGER,
                description: 'How many sources to return, 1-20. Defaults to 5. Raise it for a broad survey, '
                    . 'lower it when you want one authoritative answer.',
                required: false,
            ),
            new ToolProperty(
                name: 'include_domains',
                type: PropertyType::STRING,
                description: 'Comma-separated list of domains to restrict the search to, e.g. '
                    . '"sec.gov, reuters.com". Use it when the user names a source, or to search inside one '
                    . 'site. Leave it out to search the whole web.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $query,
        ?string $topic = null,
        ?string $time_range = null,
        ?int $max_results = null,
        ?string $include_domains = null,
    ): array {
        $query = trim($query);

        if ($query === '') {
            return ['error' => 'The query was empty. Pass the question you want answered.'];
        }

        $client = $this->resolveTavilyClientOrError();
        if (is_array($client)) {
            return $client;
        }

        $domains = $this->splitCommaList($include_domains);

        $options = array_filter(
            [
                'max_results' => max(1, min($max_results ?? 5, 20)),
                'topic' => $this->optionalText($topic),
                'time_range' => $this->optionalText($time_range),
                'include_domains' => $domains === [] ? null : $domains,
            ],
            static fn (mixed $value): bool => $value !== null,
        );

        try {
            $response = $client->search($query, $options);
        } catch (Throwable $e) {
            report($e);

            return ['error' => 'The web search failed: ' . $e->getMessage()];
        }

        $results = array_map(
            fn (array $result): array => [
                'title' => $result['title'] ?? '',
                'url' => $result['url'] ?? '',
                'content' => $result['content'] ?? '',
                'score' => $result['score'] ?? null,
            ],
            $response['results'] ?? [],
        );

        if ($results === [] && trim((string) ($response['answer'] ?? '')) === '') {
            return ['error' => 'The web search returned nothing for "' . $query . '". Try a broader or '
                . 'differently worded query before telling the user it could not be found.'];
        }

        return [
            'query' => $query,
            'answer' => $response['answer'] ?? null,
            'results' => $results,
        ];
    }
}
