<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\DataTransferObject\Crm\CrmCustomer;
use Kanvas\Users\Models\Users;

/**
 * The bare people row of a CRM customer. Contacts, address and custom fields are applied separately,
 * the same way they are for a people that already exists, so a new and an old customer take one path.
 */
class CreateCrmPeopleAction
{
    public function __construct(
        protected readonly AppInterface $app,
        protected readonly CompanyInterface $company,
        protected readonly Users $user,
        protected readonly CrmCustomer $customer
    ) {
    }

    public function execute(): People
    {
        $people = new People([
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $this->user->getId(),
            'firstname' => $this->customer->firstname,
            'middlename' => $this->customer->middlename,
            'lastname' => $this->customer->lastname,
            'name' => trim($this->customer->firstname . ' ' . $this->customer->lastname),
        ]);
        $people->disableWorkflows();
        $people->saveOrFail();

        return $people;
    }
}
