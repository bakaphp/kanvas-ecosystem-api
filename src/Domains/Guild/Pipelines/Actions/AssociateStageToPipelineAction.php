<?php

declare(strict_types=1);

namespace Kanvas\Guild\Pipelines\Actions;

use Kanvas\Guild\Pipelines\Models\Pipeline as ModelsPipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;

class AssociateStageToPipelineAction
{
    public function __construct(
        public ModelsPipeline $pipeline,
        public array $stages
    ) {
    }

    public function execute(): void
    {
        $incomingStageIds = collect($this->stages)
            ->pluck('stages_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        // Remove only stages that are no longer in the input
        $deleteQuery = $this->pipeline->stages();
        if (! empty($incomingStageIds)) {
            $deleteQuery->whereNotIn('id', $incomingStageIds);
        }
        $deleteQuery->delete();

        // Create new or update existing stages
        foreach ($this->stages as $stage) {
            $stageId = ! empty($stage['stages_id']) ? (int) $stage['stages_id'] : null;

            PipelineStage::updateOrCreate(
                [
                    'id' => $stageId,
                    'pipelines_id' => $this->pipeline->id,
                ],
                [
                    'name' => $stage['name'],
                    'rotting_days' => $stage['rotting_days'],
                    'weight' => $stage['weight'],
                    'has_rotting_days' => 0,
                    'config' => $stage['config'] ?? null,
                    'pipelines_id' => $this->pipeline->id,
                ]
            );
        }
    }
}
