<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Providers;

use Illuminate\Support\Facades\Log;
use Kanvas\Intelligence\Agents\Exceptions\ProviderContentBlockedException;
use Kanvas\Intelligence\Agents\Exceptions\ProviderMalformedToolCallException;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\Gemini\Gemini;
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
    public function chat(Message ...$messages): Message
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
     * Gemini can finish a candidate with `content` but no `parts` — an empty STOP, usually on a long
     * prompt it chose not to answer. Neuron only guards MAX_TOKENS and otherwise dies on
     * `Undefined array key "parts"` (KANVAS-ECOSYSTEM-691). Treat it as the empty answer it is, so
     * callers take their existing empty-reply path instead of failing the turn.
     */
    #[Override]
    protected function processChatResult(array $result): AssistantMessage
    {
        $this->assertNotBlocked($result);
        $this->assertToolCallWellFormed($result);

        if (isset($result['candidates'][0]['content']) && ! isset($result['candidates'][0]['content']['parts'])) {
            Log::warning('Gemini returned a candidate with no parts; treating it as an empty reply.', [
                'model' => $this->model,
                'finish_reason' => $result['candidates'][0]['finishReason'] ?? 'UNKNOWN',
            ]);

            $result['candidates'][0]['content']['parts'] = [];
        }

        return parent::processChatResult($result);
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
