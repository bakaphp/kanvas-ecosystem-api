<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Tavily;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesTavilyClientForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Deliberately NOT keyed by inputs: one crawl can be dozens of page fetches, so the default per-name
 * budget of 10 per turn is the throttle that keeps an agent from spending a tenant's credits exploring.
 * The page cap and the per-page truncation are the other half of that — a crawl at Tavily's own
 * defaults returns more text than a turn's context window can hold.
 */
#[AgentTool(name: 'Tavily Crawl Site', category: 'knowledge')]
class TavilyCrawlTool extends Tool
{
    use ResolvesTavilyClientForTool;

    private const int MAX_PAGES = 25;
    private const int MAX_CONTENT_LENGTH = 3000;

    public function __construct()
    {
        parent::__construct(
            name: 'tavily_crawl_site',
            description: 'Explore a website from a starting URL and read the pages it links to, returning '
                . 'each page as markdown. Use it when the answer is spread across a site rather than on '
                . 'one page — "what does this company sell", "find their pricing and terms", "summarise '
                . 'this documentation". It is the most expensive research tool here: when you already know '
                . 'the exact page, read that URL directly instead, and when you only need to know what '
                . 'pages exist, map the site instead.',
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
                name: 'url',
                type: PropertyType::STRING,
                description: 'The URL to start from, including the scheme — e.g. https://example.com.',
                required: true,
            ),
            new ToolProperty(
                name: 'instructions',
                type: PropertyType::STRING,
                description: 'Plain-language direction for which pages matter, e.g. "only pricing and plan '
                    . 'comparison pages". Supplying it is the difference between a focused crawl and '
                    . 'reading the whole site, so give it whenever you know what you are after.',
                required: false,
            ),
            new ToolProperty(
                name: 'max_depth',
                type: PropertyType::INTEGER,
                description: 'How many links deep to follow from the starting URL, 1-5. Defaults to 1. '
                    . 'Raise it only when the pages you want are not linked from the start page.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Maximum pages to read, up to ' . self::MAX_PAGES . '. Defaults to 10.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $url,
        ?string $instructions = null,
        ?int $max_depth = null,
        ?int $limit = null,
    ): array {
        $url = trim($url);

        $invalid = $this->rejectInvalidUrl($url);
        if ($invalid !== null) {
            return $invalid;
        }

        $client = $this->resolveTavilyClientOrError();
        if (is_array($client)) {
            return $client;
        }

        $options = array_filter(
            [
                'max_depth' => max(1, min($max_depth ?? 1, 5)),
                'limit' => max(1, min($limit ?? 10, self::MAX_PAGES)),
                'instructions' => $this->optionalText($instructions),
            ],
            static fn (mixed $value): bool => $value !== null,
        );

        try {
            $response = $client->crawl($url, $options);
        } catch (Throwable $e) {
            report($e);

            return ['error' => 'The crawl failed: ' . $e->getMessage()];
        }

        $pages = $this->mapPagesFromResults($response['results'] ?? [], self::MAX_CONTENT_LENGTH);

        if ($pages === []) {
            return ['error' => 'The crawl of ' . $url . ' returned no readable pages. Check the URL is '
                . 'right, or read a specific page directly instead of crawling.'];
        }

        return [
            'base_url' => $response['base_url'] ?? $url,
            'page_count' => count($pages),
            'pages' => $pages,
        ];
    }
}
