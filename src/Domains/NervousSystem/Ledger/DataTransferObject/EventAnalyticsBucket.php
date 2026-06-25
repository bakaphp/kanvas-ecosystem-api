<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\DataTransferObject;

use Spatie\LaravelData\Data;

class EventAnalyticsBucket extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly int $count,
        public readonly int $distinctEntities,
    ) {
    }
}
