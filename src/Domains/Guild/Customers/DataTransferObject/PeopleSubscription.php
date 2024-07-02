<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\DataTransferObject;

use Spatie\LaravelData\Data;
use Kanvas\Apps\Models\Apps;

class PeopleSubscription extends Data
{
    public function __construct(
        public Apps $app,
        public int $peoples_id,
        public string $subscription_type,
        public string $status,
        public string $first_date,
        public string $start_date,
        public ?string $end_date = null,
        public ?string $next_renewal = null,
        public ?string $metadata = null,
    ) {
    }
}
