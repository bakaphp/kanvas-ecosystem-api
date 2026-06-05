<?php

declare(strict_types=1);

namespace Kanvas\Event\Reports\DataTransferObject;

use Spatie\LaravelData\Data;

class OrganizationEventActivity extends Data
{
    public function __construct(
        public ?int $organization_id,
        public string $organization_name,
        public int $count,
        public int $unique_people_count,
        public ?string $first_event_date,
        public ?string $last_event_date,
        public bool $had_prior_activity,
    ) {
    }
}
