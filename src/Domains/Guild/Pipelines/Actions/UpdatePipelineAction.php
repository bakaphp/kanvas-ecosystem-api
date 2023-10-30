<?php

declare(strict_types=1);

namespace Kanvas\Guild\Pipelines\Actions;

use Kanvas\Guild\Pipelines\DataTransferObject\Pipeline;
use Kanvas\Guild\Pipelines\Models\Pipeline as ModelsPipeline;

class UpdatePipelineAction
{
    /**
     * __construct.
     */
    public function __construct(
        protected readonly ModelsPipeline $pipeline,
        protected readonly Pipeline $pipelineData,
    ) {
    }

    /**
     * execute.
     * @psalm-suppress MixedReturnStatement
     */
    public function execute(): ModelsPipeline
    {
        $this->pipeline->update([
            'name' => $this->pipelineData->name,
            'weight' => $this->pipelineData->weight,
            'is_default' => $this->pipelineData->isDefault,
            //'stages' => $this->pipelineData->stages,
            'slug' => $this->pipelineData->slug ?? $this->pipeline->slug,
        ]);

        //update stages

        return $this->pipeline;
    }
}
