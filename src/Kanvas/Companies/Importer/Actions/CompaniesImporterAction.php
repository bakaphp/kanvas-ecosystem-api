<?php

declare(strict_types=1);

namespace Kanvas\Companies\Importer\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\DataTransferObject\CompaniesPostData;
use Kanvas\Companies\Importer\DataTransferObject\CompaniesImporter;
use Kanvas\Companies\Models\Companies;
use Throwable;

class CompaniesImporterAction
{
    /**
    * __construct.
    */
    public function __construct(
        public CompaniesImporter $importedCompany,
        public ?Companies $company = null,
    ) {
    }

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
                $companiesDto = CompaniesPostData::from([
                    'name' => $this->importedCompany->name,
                    'users_id' => $this->importedCompany->users_id,
                    'email' => $this->importedCompany->email,
                    'phone' => $this->importedCompany->phone,
                    'currency_id' => $this->importedCompany->currency_id,
                    'website' => $this->importedCompany->website,
                    'address' => $this->importedCompany->address,
                    'zipcode' => $this->importedCompany->zipcode,
                    'language' => $this->importedCompany->language,
                    'timezone' => $this->importedCompany->timezone,
                    'country_code' => $this->importedCompany->country_code,
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