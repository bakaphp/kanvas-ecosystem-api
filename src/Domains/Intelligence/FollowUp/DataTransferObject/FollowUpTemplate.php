<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\DataTransferObject;

use Kanvas\Intelligence\FollowUp\Models\FollowUpDay;
use Spatie\LaravelData\Data;

/**
 * @deprecated v1 follow-up engine reads stage config via FollowUpConfig DTO
 *             and resolves templates by name through the existing Templates
 *             model — see docs/intelligence/follow-up-deprecation-spec.md.
 */
class FollowUpTemplate extends Data
{
    public function __construct(
        public readonly FollowUpDay $followUpDay,
        public readonly string $communication_channel,
        public readonly string $name,
        public readonly string $template,
    ) {
    }
}
