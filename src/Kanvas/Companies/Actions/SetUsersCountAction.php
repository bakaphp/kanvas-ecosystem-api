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
        $totalUsers = CompaniesRepository::getAllCompanyUserBuilder($this->company)->count();
        //$count = count($users->toArray());
        $this->company->set('total_users', $totalUsers);

        return $totalUsers;
    }
}
