<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Providers;

use Illuminate\Support\Facades\Log;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Providers\Gemini\Gemini;
use Override;

class KanvasGemini extends Gemini
{
    use RecoversUnknownToolCalls;

    /**
     * Gemini can finish a candidate with `content` but no `parts` — an empty STOP, usually on a long
     * prompt it chose not to answer. Neuron only guards MAX_TOKENS and otherwise dies on
     * `Undefined array key "parts"` (KANVAS-ECOSYSTEM-691). Treat it as the empty answer it is, so
     * callers take their existing empty-reply path instead of failing the turn.
     */
    #[Override]
    protected function processChatResult(array $result): AssistantMessage
    {
        if (isset($result['candidates'][0]['content']) && ! isset($result['candidates'][0]['content']['parts'])) {
            Log::warning('Gemini returned a candidate with no parts; treating it as an empty reply.', [
                'model' => $this->model,
                'finish_reason' => $result['candidates'][0]['finishReason'] ?? 'UNKNOWN',
            ]);

            $result['candidates'][0]['content']['parts'] = [];
        }

        return parent::processChatResult($result);
    }
}
