<?php

declare(strict_types=1);

namespace Kanvas\Companies\Actions;

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

        CompaniesRepository::userAssociatedToCompany($companies, $this->user);
        $companies->updateOrFail($this->data->toArray());

        return $companies;
    }
}
