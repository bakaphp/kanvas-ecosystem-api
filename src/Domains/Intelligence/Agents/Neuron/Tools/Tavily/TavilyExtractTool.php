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
 * Batched on purpose — Tavily bills per call, not per URL, so reading five pages in one call costs a
 * fifth of what five calls cost and leaves the per-turn run budget intact.
 */
#[AgentTool(name: 'Tavily Read URL', category: 'knowledge')]
class TavilyExtractTool extends Tool implements HasRunKey
{
    use ResolvesTavilyClientForTool;
    use TrackByInputs;

    private const int MAX_URLS = 20;
    private const int MAX_CONTENT_LENGTH = 20000;

    public function __construct()
    {
        parent::__construct(
            name: 'tavily_read_url',
            description: 'Read the full content of one or more web pages you already have URLs for, '
                . 'returned as markdown. Use it after a web search when the snippets are not enough, or '
                . 'whenever the user gives you a link and asks what it says. Pass every URL you need in a '
                . 'single call — it costs the same as one. It cannot find pages, only read ones you name.',
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
                name: 'urls',
                type: PropertyType::STRING,
                description: 'One URL, or several separated by commas, each including the scheme — e.g. '
                    . '"https://example.com/pricing, https://example.com/about". Up to 20 per call.',
                required: true,
            ),
            new ToolProperty(
                name: 'query',
                type: PropertyType::STRING,
                description: 'What you are looking for on those pages. Supplying it reranks the extracted '
                    . 'text toward the relevant parts, which matters on long pages.',
                required: false,
            ),
            new ToolProperty(
                name: 'extract_depth',
                type: PropertyType::STRING,
                description: 'Use "advanced" when a "basic" read came back empty or clearly incomplete — '
                    . 'it renders scripted pages, tables and embedded content, at a higher cost. Defaults '
                    . 'to "basic".',
                required: false,
                enum: ['basic', 'advanced'],
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $urls, ?string $query = null, ?string $extract_depth = null): array
    {
        $targets = $this->splitUrls($urls);

        if ($targets === []) {
            return ['error' => 'No URL was passed. Give at least one full http(s) address to read.'];
        }

        foreach ($targets as $target) {
            $invalid = $this->rejectInvalidUrl($target);
            if ($invalid !== null) {
                return $invalid;
            }
        }

        if (count($targets) > self::MAX_URLS) {
            return ['error' => 'Too many URLs (' . count($targets) . '). Read at most '
                . self::MAX_URLS . ' per call and split the rest into another call.'];
        }

        $client = $this->resolveTavilyClientOrError();
        if (is_array($client)) {
            return $client;
        }

        $options = array_filter(
            [
                'extract_depth' => $this->optionalText($extract_depth) ?? 'basic',
                'query' => $this->optionalText($query),
            ],
            static fn (mixed $value): bool => $value !== null,
        );

        try {
            $response = $client->extract($targets, $options);
        } catch (Throwable $e) {
            report($e);

            return ['error' => 'Reading the page(s) failed: ' . $e->getMessage()];
        }

        $pages = $this->mapPagesFromResults($response['results'] ?? [], self::MAX_CONTENT_LENGTH);

        $failed = array_map(
            fn (array $failure): array => [
                'url' => $failure['url'] ?? '',
                'reason' => $failure['error'] ?? 'unknown',
            ],
            $response['failed_results'] ?? [],
        );

        if ($pages === []) {
            return ['error' => 'None of those pages could be read'
                . ($failed === [] ? '.' : ': ' . (string) json_encode($failed))
                . ' Try extract_depth "advanced" once before telling the user the page is unavailable.'];
        }

        return [
            'pages' => $pages,
            'failed' => $failed,
        ];
    }

    /**
     * Split only on a comma that starts the next URL. A plain explode would break the legitimate ones
     * that carry a comma of their own — map coordinates, matrix parameters, a list-valued query arg —
     * and the model would be told its perfectly good link was malformed.
     *
     * @return list<string>
     */
    private function splitUrls(string $urls): array
    {
        return array_values(array_filter(array_map(
            static fn (string $url): string => trim($url),
            preg_split('/,\s*(?=https?:\/\/)/i', $urls) ?: [],
        )));
    }
}
