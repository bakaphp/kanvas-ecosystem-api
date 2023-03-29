<?php

declare(strict_types=1);

namespace Kanvas\Users\Actions;

use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedCompanies;
use Kanvas\Users\Repositories\UsersRepository;

class AssignCompanyAction
{
    public Users $user;
    public Companies $company;

    public function __construct(string $email, int $companiesId)
    {
        $this->user = UsersRepository::getByEmail($email);
        $this->company = CompaniesRepository::getById($companiesId);
    }

    /**
     * execute
     */
    public function execute(): void
    {
        // The correct way to do this is using the method attach() from the relationship
        // But the relationship is builded using hasManyThrough and it doesn't work
        UsersAssociatedCompanies::firstOrCreate([
            'users_id' => $this->user->id,
            'companies_id' => $this->company->id,
        ]);
    }
}
