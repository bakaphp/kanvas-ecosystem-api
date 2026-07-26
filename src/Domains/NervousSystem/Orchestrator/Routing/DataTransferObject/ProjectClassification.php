<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Routing\DataTransferObject;

use Spatie\LaravelData\Data;

/**
 * The LLM's answer to "which project does this signal belong to?" — cascade step (b). `projectId` is
 * null when no candidate fits (no action needed); otherwise it's the chosen candidate's id with a
 * 0.0–1.0 confidence the caller bands (≥0.7 auto, 0.4–0.7 approval, <0.4 triage).
 */
final class ProjectClassification extends Data
{
    public function __construct(
        public readonly ?int $projectId,
        public readonly float $confidence,
        public readonly string $reason,
    ) {
    }

    public static function none(string $reason): self
    {
        return new self(null, 0.0, $reason);
    }
}
