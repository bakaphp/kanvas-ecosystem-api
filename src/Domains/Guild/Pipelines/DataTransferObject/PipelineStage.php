<?php

declare(strict_types=1);

namespace Kanvas\Guild\Pipelines\DataTransferObject;

use Kanvas\Guild\Pipelines\Models\Pipeline;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class PipelineStage extends Data
{
    /**
     * __construct.
     *
     * A null $weight keeps the stage's current weight, or takes the next one for a new stage.
     *
     * $config is Optional when the input omits it, so an update from a client that never sends
     * config cannot wipe a stage's follow-up setup. An explicit null clears it.
     */
    public function __construct(
        public readonly Pipeline $pipeline,
        public readonly string $name,
        public readonly ?int $weight = null,
        public readonly int $rotting_days = 0,
        public readonly array|string|Optional|null $config = new Optional(),
    ) {
    }

    /**
     * A weight of 0 from a single-stage input means "not set". $weight lets a caller that knows the
     * position pass it explicitly, 0 included.
     *
     *  @psalm-suppress ArgumentTypeCoercion
     */
    public static function viaRequest(
        Pipeline $pipeline,
        array $request,
        ?int $weight = null
    ): self {
        return new self(
            $pipeline,
            (string) $request['name'],
            $weight ?? (((int) ($request['weight'] ?? 0)) ?: null),
            (int) ($request['rotting_days'] ?? 0),
            array_key_exists('config', $request) ? $request['config'] : new Optional(),
        );
    }
}
