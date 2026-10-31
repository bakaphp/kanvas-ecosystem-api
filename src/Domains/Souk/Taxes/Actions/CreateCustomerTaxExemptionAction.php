<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Taxes\Models\CustomerTaxExemption;

class CreateCustomerTaxExemptionAction
{
    public function __construct(
        private readonly Apps $app,
        private readonly array $data,
    ) {
    }

    public function execute(): CustomerTaxExemption
    {
        return DB::connection('commerce')->transaction(function () {
            $exemption = new CustomerTaxExemption();
            $exemption->apps_id = $this->app->getId();
            $exemption->people_id = $this->data['people_id'];
            $exemption->exemption_type = $this->data['exemption_type'];
            $exemption->certificate_number = $this->data['certificate_number'] ?? null;
            $exemption->valid_from = $this->data['valid_from'] ?? null;
            $exemption->valid_to = $this->data['valid_to'] ?? null;
            $exemption->saveOrFail();

            return $exemption;
        });
    }
}
