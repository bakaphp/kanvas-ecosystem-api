<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
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
        return DB::connection('commerce')->transaction(function () {
            $this->assertNoOverlap();

            $this->registration->fill(Arr::only($this->data, self::FIELDS));
            $this->registration->saveOrFail();

            return $this->registration;
        });
    }

    private function assertNoOverlap(): void
    {
        $country = $this->data['jurisdiction_country'] ?? $this->registration->jurisdiction_country;
        $state = array_key_exists('jurisdiction_state', $this->data)
            ? $this->data['jurisdiction_state']
            : $this->registration->jurisdiction_state;
        $newFrom = array_key_exists('effective_from', $this->data)
            ? $this->data['effective_from']
            : $this->registration->effective_from;
        $newTo = array_key_exists('effective_to', $this->data)
            ? $this->data['effective_to']
            : $this->registration->effective_to;

        $overlaps = TaxRegistration::query()
            ->fromApp($this->registration->app)
            ->notDeleted()
            ->forJurisdiction($country, $state)
            ->where('id', '!=', $this->registration->id)
            ->lockForUpdate()
            ->get()
            ->contains(fn (TaxRegistration $other) => $other->overlaps($newFrom, $newTo));

        if ($overlaps) {
            throw new ValidationException(
                'A tax registration for this jurisdiction already exists in the specified date range.'
            );
        }
    }
}
