<?php

declare(strict_types=1);

namespace Kanvas\Companies\Actions;

use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;

class SetUsersCountAction
{
    public function __construct(
        public Companies $company
    ) {
    }

    public function execute(): int
    {
        $count = CompaniesRepository::getAllCompanyUsers($this->company)->count();
        $this->company->set('users_count', $count);
        return $count;
    }
}
