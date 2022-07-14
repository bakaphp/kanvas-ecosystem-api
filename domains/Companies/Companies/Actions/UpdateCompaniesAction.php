<?php

declare(strict_types=1);

namespace Kanvas\Companies\Companies\Actions;

use Kanvas\Companies\Companies\DataTransferObject\CompaniesPutData;
use Kanvas\Companies\Companies\Models\Companies;

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
        $companies->name = $this->data->name;
        $companies->update();

        return $companies;
    }
}
