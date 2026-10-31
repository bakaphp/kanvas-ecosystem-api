<?php

declare(strict_types=1);

namespace Kanvas\Guild\Pipelines\Actions;

use Kanvas\Guild\Pipelines\DataTransferObject\PipelineStage;
use Kanvas\Guild\Pipelines\Models\PipelineStage as ModelsPipelineStage;
use Spatie\LaravelData\Optional;

class UpsertPipelineStageAction
{
    public function __construct(
        protected readonly PipelineStage $stageData,
        protected readonly ?ModelsPipelineStage $stage = null,
    ) {
    }

    public function execute(): ModelsPipelineStage
    {
        $stage = $this->stage ?? $this->findByName() ?? new ModelsPipelineStage([
            'has_rotting_days' => 0,
        ]);

        $stage->fill([
            'name' => $this->stageData->name,
            'pipelines_id' => $this->stageData->pipeline->getId(),
            'rotting_days' => $this->stageData->rotting_days,
            'weight' => $this->stageData->weight ?? $stage->weight ?? $this->nextWeight(),
            'is_deleted' => 0,
        ]);

        if (! $this->stageData->config instanceof Optional) {
            $stage->config = $this->stageData->config;
        }

        $stage->saveOrFail();

        return $stage;
    }

    /**
     * A live stage wins over a soft-deleted one of the same name, which is restored only when there
     * is no live stage, so its leads and id come back instead of a duplicate.
     */
    private function findByName(): ?ModelsPipelineStage
    {
        return ModelsPipelineStage::where('pipelines_id', $this->stageData->pipeline->getId())
            ->where('name', $this->stageData->name)
            ->orderBy('is_deleted')
            ->first();
    }

    private function nextWeight(): int
    {
        return (int) ModelsPipelineStage::where('pipelines_id', $this->stageData->pipeline->getId())->max('weight') + 1;
    }
}
