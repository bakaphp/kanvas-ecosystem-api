<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Jina;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesJinaClientForTool;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Jina search returns each result already read, not summarised — no synthesized answer, just the pages.
 * That suits a question whose answer has to be quoted or compared across sources; a question wanting
 * one short factual answer is cheaper through a search tool that synthesizes.
 *
 * Content is dropped by default because Jina reads every hit: keeping it would put five full pages in
 * the turn for what is usually a "which of these is worth reading" decision.
 */
#[AgentTool(name: 'Jina Search', category: 'knowledge')]
class JinaSearchTool extends Tool implements HasRunKey
{
    use ResolvesJinaClientForTool;
    use TrackByInputs;

    private const int MAX_CONTENT_LENGTH = 4000;

    public function __construct()
    {
        parent::__construct(
            name: 'jina_search',
            description: 'Search the web and get back the matching pages themselves rather than a summary. '
                . 'Use it when you need to quote sources, compare what several pages say, or read the '
                . 'results in full — set include_content true for that. Leave include_content off to just '
                . 'see what is out there and pick a page to read properly afterwards.',
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
                description: 'What to search for. Be specific — name the company or subject and what you '
                    . 'need about it, not a bare keyword.',
                required: true,
            ),
            new ToolProperty(
                name: 'include_content',
                type: PropertyType::BOOLEAN,
                description: 'True to return the text of every result, false (the default) for titles, '
                    . 'URLs and descriptions only. Turning it on is much more expensive in context, so use '
                    . 'it when you genuinely need to read the pages rather than choose between them.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $query, ?bool $include_content = null): array
    {
        $query = trim($query);

        if ($query === '') {
            return ['error' => 'The query was empty. Pass what you want to search for.'];
        }

        $client = $this->resolveJinaClientOrError();
        if (is_array($client)) {
            return $client;
        }

        $withContent = $include_content ?? false;

        try {
            // Asking Jina not to send content at all is what makes the cheap mode cheap — trimming it
            // after the fact would still have paid for every page in the response.
            $hits = $client->search($query, $withContent ? [] : ['X-Respond-With' => 'no-content']);
        } catch (Throwable $e) {
            report($e);

            return ['error' => 'The web search failed: ' . $e->getMessage()];
        }

        $results = array_values(array_map(
            fn (mixed $hit): array => $this->presentResult($hit, $withContent),
            $hits,
        ));

        if ($results === []) {
            return ['error' => 'Nothing was found for "' . $query . '". Try a broader or differently '
                . 'worded query before telling the user it does not exist.'];
        }

        return [
            'query' => $query,
            'results' => $results,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentResult(mixed $result, bool $withContent): array
    {
        $result = is_array($result) ? $result : [];

        $presented = [
            'title' => (string) ($result['title'] ?? ''),
            'url' => (string) ($result['url'] ?? ''),
            'description' => (string) ($result['description'] ?? ''),
        ];

        if (! $withContent) {
            return $presented;
        }

        $presented['content'] = $this->truncateContent(
            (string) ($result['content'] ?? ''),
            self::MAX_CONTENT_LENGTH,
        );

        return $presented;
    }
}
