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
 * The same traversal a crawl does, returning only the URLs it found.
 *
 * The cheap first step of site research: map to see what exists, then read the two or three pages that
 * matter, rather than crawling everything to find them.
 */
#[AgentTool(name: 'Tavily Map Site', category: 'knowledge')]
class TavilyMapTool extends Tool
{
    use ResolvesTavilyClientForTool;

    private const int MAX_LINKS = 200;

    public function __construct()
    {
        parent::__construct(
            name: 'tavily_map_site',
            description: 'List the pages of a website without reading any of them — you get URLs back, no '
                . 'content. Use it to find out what a site contains before deciding what to read: "does '
                . 'this company publish pricing", "find their careers page", "how is this documentation '
                . 'organised". Much cheaper and faster than crawling, so prefer it whenever you need '
                . 'to locate a page rather than read one.',
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
                description: 'Plain-language direction for which pages matter, e.g. "documentation and API '
                    . 'reference pages only".',
                required: false,
            ),
            new ToolProperty(
                name: 'max_depth',
                type: PropertyType::INTEGER,
                description: 'How many links deep to follow from the starting URL, 1-5. Defaults to 1.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Maximum URLs to return, up to ' . self::MAX_LINKS . '. Defaults to 50.',
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
                'limit' => max(1, min($limit ?? 50, self::MAX_LINKS)),
                'instructions' => $this->optionalText($instructions),
            ],
            static fn (mixed $value): bool => $value !== null,
        );

        try {
            $response = $client->map($url, $options);
        } catch (Throwable $e) {
            report($e);

            return ['error' => 'Mapping the site failed: ' . $e->getMessage()];
        }

        /** @var array<array-key, mixed> $discovered */
        $discovered = $response['results'] ?? [];

        $links = array_values(array_filter(
            $discovered,
            static fn (mixed $link): bool => is_string($link) && $link !== '',
        ));

        if ($links === []) {
            return ['error' => 'No pages were found under ' . $url . '. Check the URL is right, or search '
                . 'the web for the site instead.'];
        }

        return [
            'base_url' => $response['base_url'] ?? $url,
            'link_count' => count($links),
            'links' => $links,
        ];
    }
}
