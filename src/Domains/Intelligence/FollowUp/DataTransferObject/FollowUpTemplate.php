<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\DataTransferObject;

use Kanvas\Intelligence\FollowUp\Models\FollowUpDay;
use Spatie\LaravelData\Data;

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
