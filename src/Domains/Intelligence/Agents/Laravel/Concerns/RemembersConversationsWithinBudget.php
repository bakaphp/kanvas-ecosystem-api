<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Concerns;

use Kanvas\Intelligence\Agents\ChatHistory\LaravelHistoryBudgetTrimmer;
use Laravel\Ai\Concerns\RemembersConversations;

/**
 * `RemembersConversations` with the replayed history cut to the model's token budget instead of the
 * package's flat 100 rows. Use this on a Kanvas Laravel agent, never the package trait directly.
 */
trait RemembersConversationsWithinBudget
{
    use RemembersConversations {
        RemembersConversations::messages as conversationMessages;
    }

    public function messages(): iterable
    {
        return LaravelHistoryBudgetTrimmer::trim($this->conversationMessages(), $this->historyTokenBudget());
    }

    /**
     * A hydration cap, not the budget — the token trim decides, so this sits far above what any window
     * holds (the Neuron history's `MAX_LOADED_ROWS` plays the same role).
     */
    protected function maxConversationMessages(): int
    {
        return 1_000;
    }
}
