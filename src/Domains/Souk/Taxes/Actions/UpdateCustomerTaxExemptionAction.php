<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Souk\Taxes\Models\CustomerTaxExemption;

class UpdateCustomerTaxExemptionAction
{
    public function __construct(
        private readonly CustomerTaxExemption $exemption,
        private readonly array $data,
    ) {
    }

    public function execute(): CustomerTaxExemption
    {
        return DB::connection('commerce')->transaction(function () {
            if (array_key_exists('exemption_type', $this->data)) {
                $this->exemption->exemption_type = $this->data['exemption_type'];
            }
            if (array_key_exists('certificate_number', $this->data)) {
                $this->exemption->certificate_number = $this->data['certificate_number'];
            }
            if (array_key_exists('valid_from', $this->data)) {
                $this->exemption->valid_from = $this->data['valid_from'];
            }
            if (array_key_exists('valid_to', $this->data)) {
                $this->exemption->valid_to = $this->data['valid_to'];
            }

            $this->exemption->saveOrFail();

            return $this->exemption;
        });
    }
}
