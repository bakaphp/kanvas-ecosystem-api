<?php

declare(strict_types=1);

namespace Kanvas\Companies\Actions;

use Kanvas\Companies\DataTransferObject\CompaniesPutData;
use Kanvas\Companies\Models\Companies;

class UpdateCompaniesAction
{
    /**
     * Construct function.
     *
     * @param CompaniesPutData $data
     */
    public function __construct(
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
        $companies->updateOrFail($this->data->spitFilledAsArray());
        return $companies;
    }
}
