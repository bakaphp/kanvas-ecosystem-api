<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\ChatHistory;

use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;

/**
 * The Laravel-path counterpart of `KanvasHistoryTrimmer`: a replayed history is cut to the same token
 * budget `ModelContextWindowService` gives the Neuron histories, so both backends forget at the same
 * point and neither ships a model its whole stored conversation. Oldest turns go first, and the cut
 * lands on a human turn — providers reject a history that opens on an assistant message or on a tool
 * result whose call was dropped.
 */
final class LaravelHistoryBudgetTrimmer
{
    /**
     * NeuronAI's TokenCounter divides characters by four; the budget was sized against that counter
     * (and discounted for it in `ModelContextWindowService::ESTIMATE_OPTIMISM`), so the Laravel side has
     * to count the same way or the two paths keep different amounts of history on the same model.
     */
    private const float CHARS_PER_TOKEN = 4.0;

    /**
     * @param iterable<int, Message> $messages oldest first
     *
     * @return list<Message>
     */
    public static function trim(iterable $messages, int $tokenBudget): array
    {
        $messages = array_values(is_array($messages) ? $messages : iterator_to_array($messages, false));

        $tailTokens = 0;
        $cut = null;

        // Candidates are human turns only, newest first; the newest exchange always survives — one turn
        // over budget is the provider's problem to report, not a reason to send an empty history.
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $tailTokens += self::tokens($messages[$i]);

            if (! self::opensATurn($messages[$i])) {
                continue;
            }

            if ($cut !== null && $tailTokens > $tokenBudget) {
                break;
            }

            $cut = $i;
        }

        return $cut === null ? [] : array_values(array_slice($messages, $cut));
    }

    public static function tokens(Message $message): int
    {
        $chars = strlen((string) $message->content);

        if ($message instanceof AssistantMessage && $message->toolCalls->isNotEmpty()) {
            $chars += strlen((string) json_encode($message->toolCalls));
        }

        if ($message instanceof ToolResultMessage && $message->toolResults->isNotEmpty()) {
            $chars += strlen((string) json_encode($message->toolResults));
        }

        return (int) ceil($chars / self::CHARS_PER_TOKEN);
    }

    private static function opensATurn(Message $message): bool
    {
        return $message->role->value === 'user' && ! $message instanceof ToolResultMessage;
    }
}
