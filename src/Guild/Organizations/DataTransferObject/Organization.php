<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\DataTransferObject;

use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Spatie\LaravelData\Data;

class Organization extends Data
{
    /**
     * __construct.
     */
    public function __construct(
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly string $name,
        public readonly ?string $description = null
    ) {
    }
}
