<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Pipelines\Actions;

use Baka\Support\Str;
use Illuminate\Support\Facades\DB;
use Kanvas\ActionEngine\Pipelines\DataTransferObject\PipelineStage as PipelineStageData;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStage;

class CreatePipelineStageAction
{
    public function __construct(
        protected readonly PipelineStageData $data,
    ) {
    }

    public function execute(): PipelineStage
    {
        return DB::connection('action_engine')->transaction(function () {
            $pipelineStage = new PipelineStage();
            $pipelineStage->pipelines_id = $this->data->pipeline->getId();
            $pipelineStage->name = $this->data->name;
            $pipelineStage->slug = Str::slug($this->data->name);
            $pipelineStage->has_rotting_days = $this->data->has_rotting_days ?? 0;
            $pipelineStage->rotting_days = $this->data->rotting_days ?? 0;
            $pipelineStage->weight = $this->data->weight ?? 0;
            $pipelineStage->saveOrFail();

            return $pipelineStage;
        });
    }
}
