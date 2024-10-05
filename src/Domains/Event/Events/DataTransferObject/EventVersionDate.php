<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\DataTransferObject;

use DateTime;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;

class EventDate extends Data
{
    public function __construct(
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d')]
        public readonly DateTime $date,
        public readonly string $startTime,
        public readonly string $endTime,
    ) {
    }
}