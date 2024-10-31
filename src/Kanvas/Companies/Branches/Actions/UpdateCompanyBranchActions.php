<?php

declare(strict_types=1);

namespace Kanvas\Companies\Branches\Actions;

use Kanvas\Companies\Branches\DataTransferObject\CompaniesBranchPutData;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Companies\Models\CompaniesBranchesAddress;
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

    public function execute(int $companyBranchId): CompaniesBranches
    {
        $companyBranch = CompaniesBranches::getById($companyBranchId);
        $company = $companyBranch->company()->first();

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
