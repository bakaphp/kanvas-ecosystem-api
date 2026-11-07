<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Kanvas\Souk\Taxes\Models\TaxRegistration;

class UpdateTaxRegistrationAction
{
    private const array FIELDS = [
        'jurisdiction_country',
        'jurisdiction_state',
        'registration_number',
        'effective_from',
        'effective_to',
        'metadata',
    ];

    public function __construct(
        private readonly TaxRegistration $registration,
        private readonly array $data,
    ) {
    }

    public function execute(): TaxRegistration
    {
        $country = $this->data['jurisdiction_country'] ?? $this->registration->jurisdiction_country;
        $state = $this->valueFor('jurisdiction_state');

        return TaxRegistration::withJurisdictionLock(
            $this->registration->app,
            $country,
            $state,
            fn () => DB::connection('commerce')->transaction(function () use ($country, $state) {
                TaxRegistration::assertNoOverlap(
                    app: $this->registration->app,
                    country: $country,
                    state: $state,
                    from: $this->valueFor('effective_from'),
                    to: $this->valueFor('effective_to'),
                    exceptId: $this->registration->id,
                );

                $this->registration->fill(Arr::only($this->data, self::FIELDS));
                $this->registration->saveOrFail();

                return $this->registration;
            })
        );
    }

    private function valueFor(string $field): ?string
    {
        return array_key_exists($field, $this->data)
            ? $this->data[$field]
            : $this->registration->{$field};
    }
}
