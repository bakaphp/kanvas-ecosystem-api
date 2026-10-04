<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Providers;

use Illuminate\Support\Facades\Log;
use Kanvas\Intelligence\Agents\Exceptions\ProviderContentBlockedException;
use Kanvas\Intelligence\Agents\Exceptions\ProviderMalformedToolCallException;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Providers\ProviderResponse;
use Override;

class KanvasGemini extends Gemini
{
    use RecoversUnknownToolCalls;

    private const array BLOCKED_FINISH_REASONS = [
        'SAFETY',
        'BLOCKLIST',
        'PROHIBITED_CONTENT',
        'SPII',
        'IMAGE_SAFETY',
    ];

    /**
     * Retry malformed output once at the inference boundary, before any returned tools run.
     * Never salvage finishMessage as a reply: it can claim actions the rejected call never performed.
     */
    #[Override]
    public function chat(Message ...$messages): ProviderResponse
    {
        try {
            return parent::chat(...$messages);
        } catch (ProviderMalformedToolCallException) {
            Log::warning('Gemini returned a malformed function call; retrying the inference.', [
                'model' => $this->model,
            ]);

            return parent::chat(...$messages);
        }
    }

    /**
     * Neuron treats a candidate with no `parts` as an empty answer; what it still reports as a generic
     * "no candidates" failure is a safety block, and what it never checks is a malformed function call.
     * Both are asserted here before the parent reads the result.
     */
    #[Override]
    protected function processChatResult(array $result): AssistantMessage
    {
        $this->assertNotBlocked($result);
        $this->assertToolCallWellFormed($result);

        return parent::processChatResult($this->withPromptTokenCount($result));
    }

    /**
     * Gemini can omit `promptTokenCount` from `usageMetadata`, which Neuron reads as zero
     * (KANVAS-ECOSYSTEM-6HD). Derive it from the total so usage and cost stay accurate.
     */
    private function withPromptTokenCount(array $result): array
    {
        $usage = $result['usageMetadata'] ?? null;

        if ($usage === null || isset($usage['promptTokenCount'])) {
            return $result;
        }

        $result['usageMetadata']['promptTokenCount'] = max(
            0,
            ($usage['totalTokenCount'] ?? 0) - ($usage['candidatesTokenCount'] ?? 0) - ($usage['thoughtsTokenCount'] ?? 0),
        );

        return $result;
    }

    private function assertToolCallWellFormed(array $result): void
    {
        $candidate = $result['candidates'][0] ?? [];

        if (($candidate['finishReason'] ?? null) !== 'MALFORMED_FUNCTION_CALL') {
            return;
        }

        throw new ProviderMalformedToolCallException(sprintf(
            'Gemini returned a malformed function call, model %s, prompt tokens %s. %s',
            $this->model,
            $result['usageMetadata']['promptTokenCount'] ?? 'unknown',
            $candidate['finishMessage'] ?? '',
        ));
    }

    /**
     * A blocked prompt comes back with `promptFeedback.blockReason` and no candidates; a blocked answer
     * comes back as a candidate with a safety finishReason and nothing to say. Neuron reports both as a
     * generic "no candidates" failure. `PROHIBITED_CONTENT` cannot be lowered through safetySettings, so
     * a retry with the same history is blocked again.
     */
    private function assertNotBlocked(array $result): void
    {
        $blockReason = $result['promptFeedback']['blockReason'] ?? null;

        if ($blockReason === null && ! isset($result['candidates'][0]['content']['parts'])) {
            $finishReason = $result['candidates'][0]['finishReason'] ?? null;

            if (in_array($finishReason, self::BLOCKED_FINISH_REASONS, true)) {
                $blockReason = $finishReason;
            }
        }

        if ($blockReason === null) {
            return;
        }

        throw new ProviderContentBlockedException(
            blockReason: (string) $blockReason,
            message: sprintf(
                'Gemini blocked the request (%s), model %s, prompt tokens %s.',
                $blockReason,
                $this->model,
                $result['usageMetadata']['promptTokenCount'] ?? 'unknown',
            ),
        );
    }
}
