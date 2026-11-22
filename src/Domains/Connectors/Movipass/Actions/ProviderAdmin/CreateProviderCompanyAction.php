<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions\ProviderAdmin;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\DataTransferObject\Company as CompanyData;
use Kanvas\Companies\Models\Companies;
use Kanvas\Services\SetupService;
use Kanvas\Users\Actions\AddAdminsToCompanyAction;
use Kanvas\Users\Models\Users;

class CreateProviderCompanyAction
{
    public function __construct(
        protected readonly Users $actor,
        protected readonly string $name,
        protected readonly ?string $email,
        protected readonly ?string $phone,
        protected readonly Apps $app,
    ) {
    }

    public function execute(): Companies
    {
        $company = new CreateCompaniesAction(
            new CompanyData(
                user: $this->actor,
                name: $this->name,
                email: $this->email,
                phone: $this->phone,
            ),
        )->execute();

        new SetupService()->onBoarding($this->actor, $this->app, $company);

        new AddAdminsToCompanyAction(
            $this->app,
            $this->actor,
            $company,
            $company->defaultBranch()->firstOrFail(),
        )->execute();

        return $company;
    }
}
