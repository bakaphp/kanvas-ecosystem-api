<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Actions;

use Kanvas\ActionEngine\Engagements\Enums\EngagementStagePositionEnum;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStage;

class ResolveEngagementStagePositionAction
{
    public function __construct(
        private readonly Engagement $engagement,
    ) {
    }

    public function execute(): ?EngagementStagePositionEnum
    {
        $stage = $this->engagement->stage;
        if ($stage === null) {
            return null;
        }

        $weights = PipelineStage::query()
            ->where('pipelines_id', $stage->pipelines_id)
            ->where('is_deleted', 0)
            ->pluck('weight');

        if ($weights->isEmpty()) {
            return null;
        }

        $current = (float) $stage->weight;

        // Last wins on ties so a single-stage pipeline reads as "completed".
        if ($current === (float) $weights->max()) {
            return EngagementStagePositionEnum::LAST;
        }

        if ($current === (float) $weights->min()) {
            return EngagementStagePositionEnum::FIRST;
        }

        return null;
    }
}
