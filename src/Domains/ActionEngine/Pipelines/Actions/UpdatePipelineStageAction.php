<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Pipelines\Actions;

use Baka\Support\Str;
use Illuminate\Support\Facades\DB;
use Kanvas\ActionEngine\Pipelines\DataTransferObject\PipelineStage as PipelineStageData;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStage;

class UpdatePipelineStageAction
{
    public function __construct(
        protected readonly PipelineStage $pipelineStage,
        protected readonly PipelineStageData $data,
    ) {
    }

    public function execute(): PipelineStage
    {
        return DB::connection('action_engine')->transaction(function () {
            $this->pipelineStage->name = $this->data->name;
            $this->pipelineStage->slug = Str::slug($this->data->name);

            if ($this->data->has_rotting_days !== null) {
                $this->pipelineStage->has_rotting_days = $this->data->has_rotting_days;
            }

            if ($this->data->rotting_days !== null) {
                $this->pipelineStage->rotting_days = $this->data->rotting_days;
            }

            if ($this->data->weight !== null) {
                $this->pipelineStage->weight = $this->data->weight;
            }

            $this->pipelineStage->saveOrFail();

            return $this->pipelineStage;
        });
    }
}
