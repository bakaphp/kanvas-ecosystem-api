<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Routing\DataTransferObject;

use Kanvas\NervousSystem\Orchestrator\Routing\Enums\RoutingOutcomeEnum;
use Kanvas\NervousSystem\Project\Models\Project;

/**
 * The orchestrator's decision for one signal. `target` is the record to deliver to (FORWARD) or the
 * suggested project (APPROVAL); null for TRIAGE (no guess to surface) and DROP (no action). The job
 * acts on `outcome`. Not queued — computed and consumed within one job run.
 */
final readonly class RoutingDecision
{
    public function __construct(
        public RoutingOutcomeEnum $outcome,
        public ?RoutingCandidate $target,
        public float $confidence,
        public string $reason,
    ) {
    }

    public static function forward(RoutingCandidate $target, float $confidence, string $reason): self
    {
        return new self(
            RoutingOutcomeEnum::FORWARD,
            $target,
            $confidence,
            $reason
        );
    }

    /**
     * Only a project is ever held for approval: a lead or organization reaches routing through a
     * real attendee, so it is never a guess a human has to confirm.
     */
    public static function approval(
        Project $project,
        float $confidence,
        string $reason
    ): self {
        return new self(
            RoutingOutcomeEnum::APPROVAL,
            RoutingCandidate::forProject($project),
            $confidence,
            $reason
        );
    }

    public static function triage(float $confidence, string $reason): self
    {
        return new self(
            RoutingOutcomeEnum::TRIAGE,
            null,
            $confidence,
            $reason
        );
    }

    public static function drop(string $reason): self
    {
        return new self(
            RoutingOutcomeEnum::DROP,
            null,
            0.0,
            $reason
        );
    }

    public function project(): ?Project
    {
        return $this->target?->project();
    }
}
