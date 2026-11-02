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

        $overlaps = TaxRegistration::query()
            ->fromApp($this->app)
            ->notDeleted()
            ->forJurisdiction($this->data['jurisdiction_country'], $this->data['jurisdiction_state'] ?? null)
            ->get()
            ->contains(fn (TaxRegistration $registration) => $registration->overlaps($newFrom, $newTo));

        if ($overlaps) {
            throw new ValidationException(
                'A tax registration for this jurisdiction already exists in the specified date range.'
            );
        }
    }
}
