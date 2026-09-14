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
 * Jina Reader renders the page in a real browser before converting it, so it returns text where a
 * plain fetch returns an empty shell — a React or Vue app, an infinite-scroll listing, anything that
 * builds its content client-side. That is the whole reason to hold this alongside a Tavily reader.
 *
 * One URL per call, unlike the batched Tavily reader: Jina bills per page either way, so batching
 * would buy nothing and only widen the blast radius of one slow page.
 */
#[AgentTool(name: 'Jina Read URL', category: 'knowledge')]
class JinaReadUrlTool extends Tool implements HasRunKey
{
    use ResolvesJinaClientForTool;
    use TrackByInputs;

    private const int MAX_CONTENT_LENGTH = 20000;

    public function __construct()
    {
        parent::__construct(
            name: 'jina_read_url',
            description: 'Read one web page and get its text back as markdown. Reach for it when another '
                . 'reader came back empty, truncated, or full of navigation instead of content — this one '
                . 'renders the page the way a browser would first, so it handles sites that build their '
                . 'content with JavaScript. It reads a page you name; it cannot find one.',
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
                description: 'The full address of the page, including the scheme — e.g. '
                    . 'https://example.com/pricing.',
                required: true,
            ),
            new ToolProperty(
                name: 'target_selector',
                type: PropertyType::STRING,
                description: 'Optional CSS selector to read just one part of the page, e.g. "article" or '
                    . '"#main". Use it when a first read buried the answer in navigation and boilerplate.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $url, ?string $target_selector = null): array
    {
        $url = trim($url);

        $invalid = $this->rejectInvalidUrl($url);
        if ($invalid !== null) {
            return $invalid;
        }

        $client = $this->resolveJinaClientOrError();
        if (is_array($client)) {
            return $client;
        }

        $selector = $this->optionalText($target_selector);

        try {
            $page = $client->read($url, $selector === null ? [] : ['X-Target-Selector' => $selector]);
        } catch (Throwable $e) {
            report($e);

            return ['error' => 'Reading ' . $url . ' failed: ' . $e->getMessage()];
        }

        $content = (string) ($page['content'] ?? '');

        if (trim($content) === '') {
            return ['error' => 'The page at ' . $url . ' returned no readable text. Do not retry the same '
                . 'way — try a more specific target_selector, or a different page.'];
        }

        return [
            'url' => $page['url'] ?? $url,
            'title' => $page['title'] ?? '',
            'content' => $this->truncateContent($content, self::MAX_CONTENT_LENGTH),
        ];
    }
}
