<?php

declare(strict_types=1);

namespace Kanvas\Companies\Branches\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Branches\DataTransferObject\CompaniesBranchPutData;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Models\Users;

class UpdateCompanyBranchActions
{
    public function __construct(
        protected Users $user,
        protected CompaniesBranchPutData $data,
        protected AppInterface $app
    ) {
    }

    public function execute(int $companyBranchId): CompaniesBranches
    {
        $companyBranch = CompaniesBranches::getById($companyBranchId);
        $company = $companyBranch->company()->first();

        $company->hasCompanyPermission($this->user, $this->app);
        CompaniesRepository::userAssociatedToCompanyAndBranch($company, $companyBranch, $this->user);

        //@todo Add observer for is_default value on company branches.
        if ($this->data->is_default === StateEnums::YES->getValue()) {
            $company->branches()->update(['is_default' => StateEnums::NO->getValue()]);
        }

        $data = array_filter($this->data->toArray(), function ($value) {
            return $value !== null;
        });

        $companyBranch->updateOrFail($data);

        if ($this->data->files) {
            $companyBranch->addMultipleFilesFromUrl($this->data->files);
        }

        return $companyBranch;
    }
}
