<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\DataTransferObject;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Spatie\LaravelData\Data;

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
