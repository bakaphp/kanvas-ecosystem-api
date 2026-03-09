<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Pipelines\DataTransferObject;

use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Spatie\LaravelData\Data;

class PipelineStage extends Data
{
    public function __construct(
        public readonly Pipeline $pipeline,
        public readonly string $name,
        public readonly ?int $has_rotting_days = null,
        public readonly ?int $rotting_days = null,
        public readonly ?int $weight = null,
    ) {
    }
}
