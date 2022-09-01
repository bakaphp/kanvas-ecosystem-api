<?php

declare(strict_types=1);

namespace Kanvas\Companies\Companies\Actions;

use Kanvas\Companies\Companies\DataTransferObject\CompaniesPostData;
use Kanvas\Companies\Companies\Models\Companies;

class CreateCompaniesAction
{
    /**
     * Construct function.
     */
    public function __construct(
        protected CompaniesPostData $data
    ) {
    }

    /**
     * Invoke function.
     *
     * @param CompaniesPostData $data
     *
     * @return Companies
     */
    public function execute() : Companies
    {
        $companies = new Companies();
        $companies->name = $this->data->name;
        $companies->saveOrFail();

        return $companies;
    }
}
