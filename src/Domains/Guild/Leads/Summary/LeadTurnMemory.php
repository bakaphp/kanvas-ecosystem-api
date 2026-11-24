<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Summary;

/**
 * The lead memory one turn runs with: the window the history, the instructions and the recall all
 * read, the summary state after any catch-up, and the prompt block built from both.
 */
final readonly class LeadTurnMemory
{
    public function __construct(
        public LeadHistoryWindow $window,
        public LeadSummaryState $state,
        public string $block,
    ) {
    }
}
