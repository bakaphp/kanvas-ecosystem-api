<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject;

use Spatie\LaravelData\Data;

/**
 * Cumulative usage for a session. A custom (openai-compatible) provider leaves the runtime's own
 * session totals at zero and reports `cost: 0`, so these are summed from the individual messages and
 * priced by Kanvas — never read from the runtime's aggregate.
 */
class HarnessUsage extends Data
{
    public function __construct(
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cacheReadTokens = 0,
        public readonly int $cacheWriteTokens = 0,
        public readonly int $reasoningTokens = 0,
    ) {
    }

    public function plus(self $other): self
    {
        return new self(
            inputTokens: $this->inputTokens + $other->inputTokens,
            outputTokens: $this->outputTokens + $other->outputTokens,
            cacheReadTokens: $this->cacheReadTokens + $other->cacheReadTokens,
            cacheWriteTokens: $this->cacheWriteTokens + $other->cacheWriteTokens,
            reasoningTokens: $this->reasoningTokens + $other->reasoningTokens,
        );
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}
