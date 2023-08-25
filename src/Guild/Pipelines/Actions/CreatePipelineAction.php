<?php

declare(strict_types=1);

namespace Kanvas\Guild\Pipelines\Actions;

use Kanvas\Guild\Pipelines\DataTransferObject\Pipeline;
use Kanvas\Guild\Pipelines\Models\Pipeline as ModelsPipeline;

class CreatePipelineAction
{
    /**
     * __construct.
     */
    public function __construct(
        protected readonly Pipeline $pipelineData,
    ) {
    }

    /**
     * execute.
     * @psalm-suppress MixedReturnStatement
     */
    public function execute(): ModelsPipeline
    {
        return ModelsPipeline::firstOrCreate([
            'companies_id' => $this->pipelineData->branch->companies_id,
            'system_modules_id' => $this->pipelineData->systemModule->getId(),
            'name' => $this->pipelineData->name,
        ], [
            'weight' => $this->pipelineData->weight,
            'users_id' => $this->pipelineData->user->getId(),
            'is_default' => $this->pipelineData->isDefault,
            'slug' => $this->pipelineData->slug,
        ]);
    }
}
