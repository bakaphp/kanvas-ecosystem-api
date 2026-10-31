<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Taxes\Models\TaxRegistration;

class UpdateTaxRegistrationAction
{
    public function __construct(
        private readonly TaxRegistration $registration,
        private readonly array $data,
    ) {
    }

    public function execute(): TaxRegistration
    {
        return DB::connection('commerce')->transaction(function () {
            $this->assertNoOverlap();

            if (array_key_exists('jurisdiction_country', $this->data)) {
                $this->registration->jurisdiction_country = $this->data['jurisdiction_country'];
            }
            if (array_key_exists('jurisdiction_state', $this->data)) {
                $this->registration->jurisdiction_state = $this->data['jurisdiction_state'];
            }
            if (array_key_exists('registration_number', $this->data)) {
                $this->registration->registration_number = $this->data['registration_number'];
            }
            if (array_key_exists('effective_from', $this->data)) {
                $this->registration->effective_from = $this->data['effective_from'];
            }
            if (array_key_exists('effective_to', $this->data)) {
                $this->registration->effective_to = $this->data['effective_to'];
            }
            if (array_key_exists('metadata', $this->data)) {
                $this->registration->metadata = $this->data['metadata'];
            }

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

        $existing = TaxRegistration::query()
            ->where('apps_id', $this->registration->apps_id)
            ->where('jurisdiction_country', $country)
            ->where('jurisdiction_state', $state)
            ->where('id', '!=', $this->registration->id)
            ->notDeleted()
            ->lockForUpdate()
            ->get();

        foreach ($existing as $other) {
            if ($this->dateRangesOverlap(
                $other->effective_from,
                $other->effective_to,
                $newFrom,
                $newTo,
            )) {
                throw new ValidationException(
                    'A tax registration for this jurisdiction already exists in the specified date range.'
                );
            }
        }
    }

    private function dateRangesOverlap(
        ?string $existingFrom,
        ?string $existingTo,
        ?string $newFrom,
        ?string $newTo,
    ): bool {
        $existingStart = $existingFrom ?? '0000-01-01';
        $existingEnd = $existingTo ?? '9999-12-31';
        $newStart = $newFrom ?? '0000-01-01';
        $newEnd = $newTo ?? '9999-12-31';

        return $existingStart <= $newEnd && $existingEnd >= $newStart;
    }
}
