<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Providers;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;

/**
 * The Responses API, not Chat Completions: gpt-6 models reject function tools together with a reasoning
 * effort on /chat/completions, and every agent turn carries tools. Stored LLM configs were written for
 * Chat Completions, so their keys are moved to the Responses shape here instead of migrating the rows.
 */
class KanvasOpenAIResponses extends OpenAIResponses
{
    use RecoversUnknownToolCalls;

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        string $key,
        string $model,
        array $parameters = [],
        bool $strict_response = false,
        ?HttpClientInterface $httpClient = null,
    ) {
        parent::__construct(
            key: $key,
            model: $model,
            parameters: self::toResponsesParameters($parameters),
            strict_response: $strict_response,
            httpClient: $httpClient,
        );
    }

    /**
     * Every turn resends the full history, so OpenAI has no reason to keep the response; `store`
     * defaults to true on this endpoint, unlike Chat Completions. A config can still opt in.
     *
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public static function toResponsesParameters(array $parameters): array
    {
        if (isset($parameters['reasoning_effort'])) {
            $parameters['reasoning'] = [...($parameters['reasoning'] ?? []), 'effort' => $parameters['reasoning_effort']];
        }

        if (isset($parameters['verbosity'])) {
            $parameters['text'] = [...($parameters['text'] ?? []), 'verbosity' => $parameters['verbosity']];
        }

        $maxTokens = $parameters['max_completion_tokens'] ?? $parameters['max_tokens'] ?? null;
        if ($maxTokens !== null) {
            $parameters['max_output_tokens'] ??= $maxTokens;
        }

        unset(
            $parameters['reasoning_effort'],
            $parameters['verbosity'],
            $parameters['max_completion_tokens'],
            $parameters['max_tokens'],
        );

        return ['store' => false, ...$parameters];
    }
}
