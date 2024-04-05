<?php

declare(strict_types=1);

namespace Kanvas\Companies\Importer\Actions;

use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\Importer\DataTransferObject\CompaniesImporter;

class CompaniesImporterAction
{

    /**
     * Run all method dor a specify product.
     *
     * @throws Throwable
     */
    public function execute(): bool
    {
        try {
            DB::connection('ecosystem')->beginTransaction();

            if ($this->company === null) {
                $companiesDto = CompaniesImporter::from([
                    'name' => $this->name,
                    'users_id' => $this->users_id,
                    'email' => $this->email,
                    'phone' => $this->phone,
                    'currency_id' => $this->currency_id,
                    'website' => $this->website,
                    'address' => $this->address,
                    'zipcode' => $this->zipcode,
                    'language' => $this->language,
                    'timezone' => $this->timezone,
                    'country_code' => $this->country_code,
                ]);
                $this->company = (new CreateCompaniesAction($companiesDto))->execute();
            }
        } catch (Throwable $e) {
            DB::connection('ecosystem')->rollback();

            throw $e;
        }

        return true;
    }
}