<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\RAG\PreProcessors;

use Illuminate\Support\Facades\Log;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\PreProcessor\PreProcessorInterface;
use Throwable;

/**
 * Runs the query rewrite only where it can change what the search finds. The rewrite is a model call the
 * customer waits on before the agent starts, and a plain sentence already searches as well as its rewrite:
 * only a terse message ("price?") or a long one mixing several asks gains from it.
 *
 * A failed rewrite searches with the customer's own words instead of failing the turn: the rewrite is an
 * optimisation of the search, never a precondition of the answer.
 */
final class GatedQueryRewrite implements PreProcessorInterface
{
    public const int TERSE_MAX_WORDS = 6;

    public const int MESSY_MIN_WORDS = 40;

    public function __construct(
        private readonly PreProcessorInterface $rewriter,
        private readonly ?int $agentId = null,
    ) {
    }

    public function process(Message $question): Message
    {
        $content = $question->getContent();

        if (! is_string($content)) {
            return $question;
        }

        $words = count(preg_split('/\s+/u', trim($content), -1, PREG_SPLIT_NO_EMPTY) ?: []);

        if ($words === 0 || ($words > self::TERSE_MAX_WORDS && $words < self::MESSY_MIN_WORDS)) {
            return $question;
        }

        $startedAt = hrtime(true);

        try {
            $rewritten = $this->rewriter->process($question);
        } catch (Throwable $e) {
            Log::warning('Agent query rewrite failed; searching with the message as written', [
                'agent_id' => $this->agentId,
                'error' => $e->getMessage(),
            ]);

            return $question;
        }

        Log::info('Agent query rewrite', [
            'agent_id' => $this->agentId,
            'words' => $words,
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
        ]);

        return $rewritten;
    }
}
