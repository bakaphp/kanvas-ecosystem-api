<?php

declare(strict_types=1);

namespace Kanvas\Companies\CorporateApplications\DataTransferObject;

use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;

final readonly class UpgradeTarget
{
    public function __construct(
        public Companies $company,
        public Users $user,
    ) {
    }
}
