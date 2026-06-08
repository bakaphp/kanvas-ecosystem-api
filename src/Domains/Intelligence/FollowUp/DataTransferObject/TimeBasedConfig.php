<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\DataTransferObject;

use Spatie\LaravelData\Data;

class TimeBasedConfig extends Data
{
    public function __construct(
        public readonly int $intervalMinutes,
        public readonly bool $advanceAfterMaxRetries = false,
    ) {
    }
}
