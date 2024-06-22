<?php

declare(strict_types=1);

namespace Kanvas\Companies\Actions;

use Kanvas\Companies\DataTransferObject\Company;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Users\Models\Users;

class UpdateCompaniesAction
{
    public function __construct(
        protected Users $user,
        protected Company $data
    ) {
    }

    /**
     * Invoke function.
     */
    public function execute(int $id): Companies
    {
        $companies = Companies::findOrFail($id);

        CompaniesRepository::userAssociatedToCompany($companies, $this->user);
        $companies->updateOrFail($this->data->toArray());

        if ($this->data->files) {
            $companies->addMultipleFilesFromUrl($this->data->files);
        }

        if ($this->data->custom_fields) {
            $companies->setAll($this->data->custom_fields);
        }

        return $companies;
    }
}
