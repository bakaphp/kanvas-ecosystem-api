<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\DataTransferObject;

use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\FollowUp\Models\FollowUp;
use Spatie\LaravelData\Data;

/**
 * @deprecated v1 follow-up engine reads stage config via FollowUpConfig DTO
 *             — see docs/intelligence/follow-up-deprecation-spec.md kill list.
 */
class FollowUpDay extends Data
{
    public function __construct(
        public readonly FollowUp $followUp,
        public readonly PipelineStage $pipelineStage,
        public readonly string $name,
        public readonly int $time_value,
        public readonly int $weight,
        public readonly bool $calendar_day = true,
        public readonly bool $send_message = false,
        public readonly ?string $time_unit = null,
        public readonly ?int $move_to_stage_id = null,
    ) {
    }
}
