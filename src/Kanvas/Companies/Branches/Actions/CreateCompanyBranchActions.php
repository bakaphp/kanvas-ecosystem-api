<?php

declare(strict_types=1);

namespace Kanvas\Companies\Branches\Actions;

use Kanvas\Companies\Branches\DataTransferObject\CompaniesBranchPostData;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Companies\Models\CompaniesBranchesAddress;
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
     */
    public function execute(): CompaniesBranches
    {
        $company = Companies::getById($this->data->companies_id);

        if ($company->branches()->count()) {
            CompaniesRepository::userAssociatedToCompany($company, $this->user);
        } else {
            $company->isOwner($this->user);
            $this->data->is_default = (int) StateEnums::YES->getValue();
        }

        if ($this->data->is_default === StateEnums::YES->getValue() && $company->branches()->count() == 1) {
            $company->branches()->update(['is_default' => StateEnums::NO->getValue()]);
        }

        $companyBranch = new CompaniesBranches();
        $companyBranch->companies_id = $this->data->companies_id;
        $companyBranch->users_id = $this->data->users_id;
        $companyBranch->is_default = $this->data->is_default;
        $companyBranch->name = $this->data->name;
        $companyBranch->email = $this->data->email;
        $companyBranch->phone = $this->data->phone;
        $companyBranch->zipcode = $this->data->zipcode;
        $companyBranch->is_active = $this->data->is_active;
        $companyBranch->saveOrFail();

        if ($this->data->files) {
            $companyBranch->addMultipleFilesFromUrl($this->data->files);
        }

        if ($this->data->address) {
            foreach ($this->data->address as $address) {
                CompaniesBranchesAddress::updateOrCreate([
                    'companies_branches_id' => $companyBranch->getId(),
                    'address' => $address['address'] ?? null,
                    'city' => $address['city'] ?? null,
                    'state' => $address['state'] ?? null,
                    'zip' => $address['zip'] ?? null,
                    'countries_id' => $address['country_id'] ?? null,
                    'states_id' => $address['state_id'] ?? null,
                    'cities_id' => $address['city_id'] ?? null,
                    'is_default' => $address['is_default'] ?? 0,
                ]);
            }
        }

        $company->associateUser($this->user, StateEnums::YES->getValue(), $companyBranch);

        return $companyBranch;
    }
}
