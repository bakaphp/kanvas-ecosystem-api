<?php

declare(strict_types=1);

namespace Kanvas\Guild\Pipelines\Actions;

use Kanvas\Guild\Pipelines\DataTransferObject\PipelineStage;
use Kanvas\Guild\Pipelines\Models\PipelineStage as ModelsPipelineStage;
use Kanvas\Guild\Pipelines\Actions\StageCounterAction;
class CreateStagePipelineAction
{
    /**
     * __construct.
     */
    public function __construct(
        protected readonly PipelineStage $stageData,
    ) {
    }

    /**
     * execute.
     * @psalm-suppress MixedReturnStatement
     */
    public function execute(): ModelsPipelineStage
    {
        $weight = ModelsPipelineStage::where('pipelines_id', $this->stageData->pipeline->getId())->max('weight') + 1;

        $stage = ModelsPipelineStage::firstOrCreate([
            'name' => $this->stageData->name,
            'pipelines_id' => $this->stageData->pipeline->getId(),
        ], [
            'weight' => $this->stageData->pipeline->stages->count() > 0 && $this->stageData->weight === 0 ? $weight : $this->stageData->weight,
            'rotting_days' => $this->stageData->rotting_days,
        ]);
        new StageCounterAction(
            $this->stageData->pipeline
        )->increase();
    }
}
