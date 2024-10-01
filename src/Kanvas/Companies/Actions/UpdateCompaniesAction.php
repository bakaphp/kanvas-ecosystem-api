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
        protected Companies $companies,
        protected Users $user,
        protected Company $data
    ) {
    }

    /**
     * Invoke function.
     */
    public function execute(): Companies
    {
        CompaniesRepository::userAssociatedToCompany($this->companies, $this->user);

        $data = array_filter($this->data->toArray(), function ($value) {
            return !is_null($value);
        });

        $this->companies->updateOrFail($data);

        if ($this->data->files) {
            $this->companies->addMultipleFilesFromUrl($this->data->files);
        }

        if ($this->data->custom_fields) {
            $this->companies->setAll($this->data->custom_fields);
        }

        return $this->companies;
    }
}
