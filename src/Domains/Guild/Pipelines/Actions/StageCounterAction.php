<?php

declare(strict_types=1);

namespace Kanvas\Guild\Pipelines\Actions;

use Kanvas\Guild\Pipelines\Enums\PipelineEnum;
use Kanvas\Guild\Pipelines\Models\Pipeline;

class StageCounterAction
{
    /**
     * __construct.
     */
    public function __construct(
        protected readonly Pipeline $pipeline,
    ) {
    }

    public function execute(): void
    {
        $this->pipeline->set(PipelineEnum::STAGE_COUNTER->value, $this->pipeline->stages->count());
    }
}
