<?php

declare(strict_types=1);

namespace Kanvas\Guild\Pipelines\DataTransferObject;

use Kanvas\Guild\Pipelines\Models\Pipeline;
use Spatie\LaravelData\Data;

class PipelineStage extends Data
{
    /**
     * __construct.
     */
    public function __construct(
        public readonly Pipeline $pipeline,
        public readonly string $name,
        public readonly int $weight = 0,
        public readonly int $rotting_days = 0
    ) {
    }

    /**
     *  @psalm-suppress ArgumentTypeCoercion
     */
    public static function viaRequest(
        Pipeline $pipeline,
        array $request
    ): self {
        return new self(
            $pipeline,
            (string) $request['name'],
            (int) ($request['weight'] ?? 0),
            (int) ($request['rotting_days'] ?? 0),
        );
    }
}
