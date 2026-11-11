<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Summary;

/**
 * What one summary step left behind: the state now stored, and why it stopped short when it did. A
 * failed step leaves the stored state exactly as it was.
 */
final readonly class LeadSummaryProgress
{
    private function __construct(
        public LeadSummaryState $state,
        public bool $saved,
        public ?string $failure,
    ) {
    }

    public static function saved(LeadSummaryState $state): self
    {
        return new self($state, saved: true, failure: null);
    }

    public static function current(LeadSummaryState $state): self
    {
        return new self($state, saved: false, failure: null);
    }

    /**
     * @param string $reason lock | provider | rejected | deadline
     */
    public static function failed(LeadSummaryState $state, string $reason): self
    {
        return new self($state, saved: false, failure: $reason);
    }
}
