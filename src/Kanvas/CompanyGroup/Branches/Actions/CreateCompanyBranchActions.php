<?php

declare(strict_types=1);

namespace Kanvas\CompanyGroup\Branches\Actions;

use Kanvas\CompanyGroup\Branches\DataTransferObject\CompaniesBranchPostData;
use Kanvas\CompanyGroup\Branches\Models\CompaniesBranches;
use Kanvas\CompanyGroup\Companies\DataTransferObject\CompaniesPostData;
use Kanvas\CompanyGroup\Companies\Models\Companies;

class CreateCompanyBranchActions
{
    /**
     * Construct function.
     */
    public function __construct(
        protected CompaniesBranchPostData $data
    ) {
    }

    /**
     * Invoke function.
     *
     * @return Companies
     */
    public function execute() : CompaniesBranches
    {
        $companyBranch = new CompaniesBranches();
        $companyBranch->companies_id = $this->data->companies_id;
        $companyBranch->users_id = $this->data->users_id;
        $companyBranch->is_default = $this->data->is_default;
        $companyBranch->name = $this->data->name;
        $companyBranch->address = $this->data->address;
        $companyBranch->email = $this->data->email;
        $companyBranch->phone = $this->data->phone;
        $companyBranch->zipcode = $this->data->zipcode;
        $companyBranch->saveOrFail();

        return $companyBranch;
    }
}
