<?php
declare(strict_types=1);

namespace Kanvas\CompanyGroup\Companies\Observers;

use Illuminate\Support\Str;
use Kanvas\Apps\Apps\Models\Apps;
use Kanvas\CompanyGroup\Branches\Actions\CreateCompanyBranchActions;
use Kanvas\CompanyGroup\Branches\DataTransferObject\CompaniesBranchPostData;
use Kanvas\CompanyGroup\Companies\Models\Companies;
use Kanvas\CompanyGroup\Companies\Repositories\CompaniesRepository;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\StateEnums;

class CompaniesObserver
{
    /**
     * Handle the Apps "saving" event.
     *
     * @param  Companies $company
     *
     * @return void
     */
    public function creating(Companies $company) : void
    {
        $company->uuid = Str::uuid()->toString();
    }

    /**
     * Handle the Apps "saving" event.
     *
     * @param  Companies $company
     *
     * @return void
     */
    public function created(Companies $company) : void
    {
        //CompaniesRepository::createBranch($company);
        $createCompanyBranch = new CreateCompanyBranchActions(
            new CompaniesBranchPostData(
                AppEnums::DEFAULT_NAME->getValue(),
                $company->id,
                $company->users_id,
                StateEnums::YES->getValue(),
                $company->email
            )
        );

        $branch = $createCompanyBranch->execute();
    }
}
