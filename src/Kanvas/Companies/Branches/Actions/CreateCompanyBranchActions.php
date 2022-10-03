<?php

declare(strict_types=1);

namespace Kanvas\Companies\Branches\Actions;

use Kanvas\Companies\Branches\DataTransferObject\CompaniesBranchPostData;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Models\Users;

class CreateCompanyBranchActions
{
    /**
     * Construct function.
     */
    public function __construct(
        protected Users $user,
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
        $company = Companies::getById($this->data->companies_id);

        if ($company->branches()->count()) {
            CompaniesRepository::userAssociatedToCompany($company, $this->user);
        } else {
            $company->isOwner($this->user);
        }

        if ($this->data->is_default === StateEnums::YES->getValue()) {
            $company->branches()->update(['is_default' => StateEnums::NO->getValue()]);
        }

        $companyBranch = new CompaniesBranches();
        $companyBranch->users_id = $this->data->users_id;
        $companyBranch->is_default = $this->data->is_default;
        $companyBranch->name = $this->data->name;
        $companyBranch->address = $this->data->address;
        $companyBranch->email = $this->data->email;
        $companyBranch->phone = $this->data->phone;
        $companyBranch->zipcode = $this->data->zipcode;

        $company->branches()->save($companyBranch);

        $company->associateUser($this->user, StateEnums::YES->getValue(), $companyBranch);

        return $companyBranch;
    }
}
