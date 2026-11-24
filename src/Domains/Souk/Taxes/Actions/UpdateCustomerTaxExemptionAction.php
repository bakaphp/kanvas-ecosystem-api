<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Kanvas\Souk\Taxes\Models\CustomerTaxExemption;

class UpdateCustomerTaxExemptionAction
{
    private const array FIELDS = [
        'exemption_type',
        'jurisdiction_country',
        'jurisdiction_state',
        'certificate_number',
        'valid_from',
        'valid_to',
        'is_active',
    ];

    public function __construct(
        private readonly CustomerTaxExemption $exemption,
        private readonly array $data,
    ) {
    }

    public function execute(): CustomerTaxExemption
    {
        return DB::connection('commerce')->transaction(function () {
            $data = Arr::only($this->data, self::FIELDS);

            if (isset($data['jurisdiction_country'])) {
                $data['jurisdiction_country'] = strtoupper($data['jurisdiction_country']);
            }

            $this->exemption->fill($data);
            $this->exemption->saveOrFail();

            return $this->exemption;
        });
    }
}
