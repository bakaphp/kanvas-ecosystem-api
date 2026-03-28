<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Spatie\LaravelData\Data;

class PeopleRelationship extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly string $name,
        public readonly ?string $description = null,
    ) {
    }
}
