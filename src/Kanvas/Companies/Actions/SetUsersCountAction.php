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
        // Modify CI
        //$users = CompaniesRepository::getAllCompanyUsers($this->company);
        //$count = count($users->toArray());
        $count = 0;
        $this->company->set('total_users', $count);

        return $count;
    }
}
