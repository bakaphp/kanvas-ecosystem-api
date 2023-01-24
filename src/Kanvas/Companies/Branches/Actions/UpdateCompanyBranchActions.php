<?php

declare(strict_types=1);

namespace Kanvas\Companies\Branches\Actions;

use Kanvas\Companies\Branches\DataTransferObject\CompaniesBranchPutData;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Models\Users;

class UpdateCompanyBranchActions
{
    /**
     * Construct function.
     */
    public function __construct(
        protected Users $user,
        protected CompaniesBranchPutData $data
    ) {
    }

    /**
     * Invoke function.
     *
     * @return Companies
     */
    public function execute(int $companyBranchId) : CompaniesBranches
    {
        $companyBranch = CompaniesBranches::getById($companyBranchId);
        $company = $companyBranch->company()->first();

        CompaniesRepository::userAssociatedToCompanyAndBranch($company, $companyBranch, $this->user);

        if ($this->data->is_default === StateEnums::YES->getValue()) {
            $company->branches()->update(['is_default' => StateEnums::NO->getValue()]);
        }

        $companyBranch->is_default = $this->data->is_default;
        $companyBranch->name = $this->data->name;
        $companyBranch->address = $this->data->address;
        $companyBranch->email = $this->data->email;
        $companyBranch->phone = $this->data->phone;
        $companyBranch->zipcode = $this->data->zipcode;
        $companyBranch->updateOrFail();

        $company->associateUser($this->user, StateEnums::YES->getValue(), $companyBranch);

        return $companyBranch;
    }
}
