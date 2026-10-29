<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Taxes\Models\TaxRegistration;

class CreateTaxRegistrationAction
{
    public function __construct(
        private readonly Apps $app,
        private readonly array $data,
    ) {
    }

    public function execute(): TaxRegistration
    {
        $this->assertNoOverlap();

        return DB::connection('commerce')->transaction(function () {
            $registration = new TaxRegistration();
            $registration->apps_id = $this->app->getId();
            $registration->jurisdiction_country = $this->data['jurisdiction_country'];
            $registration->jurisdiction_state = $this->data['jurisdiction_state'] ?? null;
            $registration->registration_number = $this->data['registration_number'] ?? null;
            $registration->provider = $this->data['provider'] ?? 'manual';
            $registration->effective_from = $this->data['effective_from'] ?? null;
            $registration->effective_to = $this->data['effective_to'] ?? null;
            $registration->is_active = $this->data['is_active'] ?? true;
            $registration->metadata = $this->data['metadata'] ?? null;
            $registration->saveOrFail();

            return $registration;
        });
    }

    private function assertNoOverlap(): void
    {
        $newFrom = $this->data['effective_from'] ?? null;
        $newTo = $this->data['effective_to'] ?? null;

        $existing = TaxRegistration::query()
            ->where('apps_id', $this->app->getId())
            ->where('jurisdiction_country', $this->data['jurisdiction_country'])
            ->where('jurisdiction_state', $this->data['jurisdiction_state'] ?? null)
            ->notDeleted()
            ->get();

        foreach ($existing as $registration) {
            if ($this->dateRangesOverlap(
                $registration->effective_from,
                $registration->effective_to,
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
