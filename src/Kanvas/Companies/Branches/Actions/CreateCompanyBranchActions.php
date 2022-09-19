<?php

declare(strict_types=1);

namespace Kanvas\Companies\Branches\Actions;

use Kanvas\Companies\Branches\DataTransferObject\CompaniesBranchPostData;
use Kanvas\Companies\Branches\Models\CompaniesBranches;
use Kanvas\Companies\Companies\DataTransferObject\CompaniesPostData;
use Kanvas\Companies\Companies\Models\Companies;

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
