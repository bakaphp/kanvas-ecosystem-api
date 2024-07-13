<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\DataTransferObject;

use Spatie\LaravelData\Data;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;

class PeopleSubscription extends Data
{
    public function __construct(
        public Apps $app,
        public People $people,
        public string $subscription_type,
        public string $status,
        public string $first_date,
        public string $start_date,
        public ?string $end_date = null,
        public ?string $next_renewal = null,
        public array $metadata = [],
    ) {
    }
}
