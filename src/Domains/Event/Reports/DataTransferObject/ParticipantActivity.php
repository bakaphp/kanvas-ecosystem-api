<?php

declare(strict_types=1);

namespace Kanvas\Event\Reports\DataTransferObject;

use Spatie\LaravelData\Data;

class ParticipantActivity extends Data
{
    public function __construct(
        public readonly int $participant_id,
        public readonly string $name,
        public readonly ?string $email,
        public readonly int $count,
        public readonly ?string $first_event_date,
        public readonly ?string $last_event_date,
        public readonly bool $had_prior_activity,
    ) {
    }
}
