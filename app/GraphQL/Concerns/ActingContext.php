<?php

declare(strict_types=1);

namespace App\GraphQL\Concerns;

use Baka\Contracts\CompanyInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;

final readonly class ActingContext
{
    public function __construct(
        public Users $user,
        public Apps $app,
        public CompanyInterface $company,
    ) {
    }
}
