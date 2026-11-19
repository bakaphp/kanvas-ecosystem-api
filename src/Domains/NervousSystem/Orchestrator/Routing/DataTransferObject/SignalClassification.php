<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Routing\DataTransferObject;

/**
 * The LLM's answer to "which record does this signal belong to?". `candidate` is null when none fits
 * (no action needed); otherwise it carries a 0.0–1.0 confidence the caller bands.
 */
final readonly class SignalClassification
{
    public function __construct(
        public ?RoutingCandidate $candidate,
        public float $confidence,
        public string $reason
    ) {
    }

    public static function none(string $reason): self
    {
        return new self(null, 0.0, $reason);
    }
}
