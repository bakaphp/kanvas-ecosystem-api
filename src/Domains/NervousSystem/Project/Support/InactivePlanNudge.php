<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Support;

use Kanvas\NervousSystem\Plan\Actions\NudgeInactivePlanAction;
use Kanvas\NervousSystem\Plan\Models\Plan;

final readonly class InactivePlanNudge
{
    public function __construct(
        public Plan $plan,
        public string $result,
    ) {
    }

    public function summary(): string
    {
        return match ($this->result) {
            NudgeInactivePlanAction::RESULT_PINGED_HUMAN => 'asked the assignee for a status update',
            NudgeInactivePlanAction::RESULT_REWOKE_AGENT => 're-woke the assigned agent',
            NudgeInactivePlanAction::RESULT_ESCALATED_AGENT => 'agent still silent after a re-wake, needs reassignment',
            NudgeInactivePlanAction::RESULT_PINGED_OWNER => 'unassigned, needs an owner',
            default => 'nudged',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->plan->getId(),
            'title' => $this->plan->title,
            'status' => $this->plan->status,
            'summary' => $this->summary(),
        ];
    }
}
