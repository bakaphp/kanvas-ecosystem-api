<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\DataTransferObject\Company;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteCustomerService;
use Kanvas\Users\Actions\AssignCompanyAction;

class SyncNetSuiteCustomerWithCompanyAction
{
    protected NetSuiteCustomerService $service;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->service = new NetSuiteCustomerService($app, $company);
    }

    public function execute(int|string $customerId): Companies
    {
        $linkCompany = Companies::getByCustomField(
            CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value,
            $customerId
        );

        if ($linkCompany) {
            return $linkCompany;
        }

        $customerInfo = $this->service->getCustomerById($customerId);

        $company = CompaniesRepository::getCompanyByNameAndApp(
            $customerInfo->companyName,
            $this->app
        );

        if ($company) {
            $company->set(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value, $customerId);

            return $company;
        }

        $createCompany = new CreateCompaniesAction(
            new Company(
                user: $this->app->keys()->firstOrFail()->user,
                name: $customerInfo->companyName,
                email: $customerInfo->email
            )
        );

        $company = $createCompany->execute();
        $company->set(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value, $customerId);

        $branch = $company->defaultBranch;
        $role = RolesRepository::getByMixedParamFromCompany(
            param: RolesEnums::ADMIN->value,
            app: $this->app
        );

        $action = new AssignCompanyAction($company->user, $branch, $role);
        $action->execute();

        return $company;
    }
}
