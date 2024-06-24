<?php

declare(strict_types=1);

namespace Kanvas\Companies\Actions;

use Kanvas\Companies\DataTransferObject\CompaniesPostData;
use Kanvas\Companies\DataTransferObject\Company;
use Kanvas\Companies\Models\Companies;

class CreateCompaniesAction
{
    /**
     * Construct function.
     */
    public function __construct(
        protected Company $data
    ) {
    }

    /**
     * Invoke function.
     *
     * @param CompaniesPostData $data
     */
    public function execute(): Companies
    {
        $companies = new Companies();
        $companies->name = $this->data->name;
        $companies->users_id = $this->data->user->getId();
        $companies->website = $this->data->website;
        $companies->phone = $this->data->phone;
        $companies->address = $this->data->address;
        $companies->zipcode = $this->data->zipcode;
        $companies->email = $this->data->email;
        $companies->phone = $this->data->phone;
        $companies->currency_id = $this->data->currency_id;
        $companies->country_code = $this->data->country_code;
        $companies->system_modules_id = 1;
        $companies->is_active = $this->data->is_active;
        $companies->saveOrFail();

        if ($this->data->files) {
            $companies->addMultipleFilesFromUrl($this->data->files);
        }

        if ($this->data->custom_fields) {
            $companies->setAll($this->data->custom_fields);
        }

        return $companies;
    }
}
