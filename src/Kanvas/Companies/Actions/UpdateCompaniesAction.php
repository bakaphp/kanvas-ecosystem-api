<?php

declare(strict_types=1);

namespace Kanvas\Companies\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Companies\DataTransferObject\CompaniesPutData;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Users\Models\Users;

class UpdateCompaniesAction
{
    /**
     * Construct function.
     *
     * @param CompaniesPutData $data
     */
    public function __construct(
        protected Users $user,
        protected CompaniesPutData $data
    ) {
    }

    /**
     * Invoke function.
     *
     * @param int $id
     *
     * @return Companies
     */
    public function execute(int $id) : Companies
    {
        $companies = Companies::findOrFail($id);

        try {
            CompaniesRepository::userAssociatedToCompany($companies, $this->user);
            $companies->updateOrFail($this->data->spitFilledAsArray());
        } catch (ModelNotFoundException $e) {
            throw new ModelNotFoundException('User doesn\'t belong to this company ' . $companies->uuid . ' , talk to the Admin');
        }

        return $companies;
    }
}
