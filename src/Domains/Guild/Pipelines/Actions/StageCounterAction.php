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

    public function increase(): void
    {
        $count = $this->pipeline->get(PipelineEnum::STAGE_COUNTER->value);
        $count = $count ? $count + 1 : 1;
        $this->pipeline->set(PipelineEnum::STAGE_COUNTER->value, $count);
    }

    public function decrease(): void
    {
        $count = $this->pipeline->get(PipelineEnum::STAGE_COUNTER->value);
        $count = $count && $count > 0 ? $count - 1 : 0;
        $this->pipeline->set(PipelineEnum::STAGE_COUNTER->value, $count);
    }
}
