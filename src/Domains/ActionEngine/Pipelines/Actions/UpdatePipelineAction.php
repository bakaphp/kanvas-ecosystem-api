<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Pipelines\Actions;

use Baka\Support\Str;
use Illuminate\Support\Facades\DB;
use Kanvas\ActionEngine\Pipelines\DataTransferObject\Pipeline as PipelineData;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;

class UpdatePipelineAction
{
    public function __construct(
        protected readonly Pipeline $pipeline,
        protected readonly PipelineData $data,
    ) {
    }

    public function execute(): Pipeline
    {
        return DB::connection('action_engine')->transaction(function () {
            $this->pipeline->name = $this->data->name;
            $this->pipeline->slug = $this->data->slug ?? Str::slug($this->data->name);

            if ($this->data->weight !== null) {
                $this->pipeline->weight = $this->data->weight;
            }

            if ($this->data->is_default !== null) {
                $this->pipeline->is_default = $this->data->is_default;
            }

            $this->pipeline->saveOrFail();

            return $this->pipeline;
        });
    }
}
