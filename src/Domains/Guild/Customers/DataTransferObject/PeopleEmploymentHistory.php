<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\DataTransferObject;

use Baka\Contracts\AppInterface;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Spatie\LaravelData\Data;

class PeopleEmploymentHistory extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly People $people,
        public readonly Organization $organization,
        public readonly string $position,
        public readonly int $status,
        public readonly ?string $start_date = null,
        public readonly ?string $end_date = null,
        public readonly ?float $income = null,
        public readonly ?string $income_type = null,
    ) {
    }
}
