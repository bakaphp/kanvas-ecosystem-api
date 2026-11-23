<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Providers;

use Baka\Support\Str;
use Kanvas\Intelligence\Agents\Neuron\Contracts\BehavesAsKanvasAgent;
use NeuronAI\Providers\AWS\BedrockRuntime;
use Override;

class KanvasBedrock extends BedrockRuntime
{
    use RecoversUnknownToolCalls;

    private const array CACHE_POINT = ['cachePoint' => ['type' => 'default']];

    /** Ours for the time, Neuron's for retrieved documents (RAG\Nodes\InstructionsNode). */
    private const array TURN_CONTEXT_OPENINGS = [
        BehavesAsKanvasAgent::TURN_CONTEXT_OPENING,
        '<EXTRA-CONTEXT>',
    ];

    /**
     * Claude on Bedrock caches only what the request marks, and Neuron marks nothing on its own. The
     * tools come first in the cached prefix and are the one part that never changes, so they get their
     * own marker, which still matches when the instructions changed. The marker before the question's
     * context is what the next turn reads; the one at the end of the conversation serves the tool loop
     * of this turn. OpenAI models cache on their own and refuse one.
     */
    #[Override]
    protected function createPayLoad(array $messages): array
    {
        $payload = parent::createPayLoad($messages);

        if (! str_contains($this->model, 'anthropic.')) {
            return $payload;
        }

        $slots = self::MAX_CACHE_POINTS;

        if (isset($payload['toolConfig']['tools'])) {
            $payload['toolConfig']['tools'][] = self::CACHE_POINT;
            $slots--;
        }

        if (isset($payload['system'])) {
            $payload['system'][] = self::CACHE_POINT;
            $slots--;
        }

        $payload['messages'] = $this->markQuestionBeforeItsContext($payload['messages']);

        $last = array_key_last($payload['messages']);
        if ($last !== null && ! isset(end($payload['messages'][$last]['content'])['cachePoint'])) {
            $payload['messages'][$last]['content'][] = self::CACHE_POINT;
        }

        $payload['messages'] = $this->keepNewestCachePoints($payload['messages'], $slots);

        return $payload;
    }

    /**
     * A turn's context (the time, retrieved documents) is appended to its question and never stored,
     * so the next turn replays the question without it and can only match a prefix that ends before it.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private function markQuestionBeforeItsContext(array $messages): array
    {
        foreach (array_reverse(array_keys($messages)) as $index) {
            $content = $messages[$index]['content'];

            if ($messages[$index]['role'] !== 'user' || array_column($content, 'toolResult') !== []) {
                continue;
            }

            foreach ($content as $position => $block) {
                if ($position > 0
                    && ! isset($content[$position - 1]['cachePoint'])
                    && Str::startsWith($block['text'] ?? '', self::TURN_CONTEXT_OPENINGS)) {
                    array_splice($messages[$index]['content'], $position, 0, [self::CACHE_POINT]);

                    return $messages;
                }
            }

            return $messages;
        }

        return $messages;
    }

    /**
     * BedrockRuntime::limitCachePoints() with a variable limit: upstream reads its limit through
     * `self::`, so it cannot be told that the tools and the instructions already took two of the four.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private function keepNewestCachePoints(array $messages, int $slots): array
    {
        foreach (array_reverse(array_keys($messages)) as $index) {
            $content = $messages[$index]['content'];

            foreach (array_reverse(array_keys($content)) as $position) {
                if (! isset($content[$position]['cachePoint'])) {
                    continue;
                }

                if ($slots > 0) {
                    $slots--;
                } else {
                    unset($content[$position]);
                }
            }

            $messages[$index]['content'] = array_values($content);
        }

        return $messages;
    }
}
