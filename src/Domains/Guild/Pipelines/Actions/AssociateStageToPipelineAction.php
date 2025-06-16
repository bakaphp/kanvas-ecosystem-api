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

    public function execute()
    {
        $this->pipeline->stages()->delete();
        foreach ($this->stages as $stage) {
            PipelineStage::updateOrCreate(
                ['id' => $stage['stages_id'] ?? null],
                [
                'name' => $stage['name'],
                'rotting_days' => $stage['rotting_days'],
                'weight' => $stage['weight'],
                'has_rotting_days' => 0,
                'pipelines_id' => $this->pipeline->id,
            ]
            );
        }
    }
}
