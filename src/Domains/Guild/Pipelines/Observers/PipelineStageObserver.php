<?php

declare(strict_types=1);

namespace Kanvas\Guild\Pipelines\Observers;

use Kanvas\Guild\Pipelines\Actions\StageCounterAction;
use Kanvas\Guild\Pipelines\Models\PipelineStage as ModelsPipelineStage;

class PipelineStageObserver
{
    public function saved(ModelsPipelineStage $stage)
    {
        new StageCounterAction(
            $stage->pipeline
        )->execute();
    }
}
