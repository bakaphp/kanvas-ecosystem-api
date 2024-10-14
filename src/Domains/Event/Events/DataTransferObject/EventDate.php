<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\DataTransferObject;

use DateTime;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Attributes\WithTransformer;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Transformers\DateTimeInterfaceTransformer;

class EventDate extends Data
{
    public function __construct(
        #[WithCast(DateTimeInterfaceCast::class)]
        #[WithTransformer(DateTimeInterfaceTransformer::class)]
        public readonly DateTime $date,
        public readonly string $start_time,
        public readonly string $end_time,
    ) {
    }

    public static function rules(): array
    {
        return [
            'start_time' => ['required', 'regex:/^([01]\d|2[0-3]):([0-5]\d)$/'],
            'end_time' => ['required', 'regex:/^([01]\d|2[0-3]):([0-5]\d)$/'],
        ];
    }
}
