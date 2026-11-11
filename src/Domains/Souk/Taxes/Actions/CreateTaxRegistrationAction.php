<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
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
        $country = $this->data['jurisdiction_country'];
        $state = $this->data['jurisdiction_state'] ?? null;

        return TaxRegistration::withJurisdictionLock(
            $this->app,
            $country,
            $state,
            fn () => DB::connection('commerce')->transaction(function () use ($country, $state) {
                TaxRegistration::assertNoOverlap(
                    $this->app,
                    $country,
                    $state,
                    $this->data['effective_from'] ?? null,
                    $this->data['effective_to'] ?? null,
                );

                $registration = new TaxRegistration();
                $registration->apps_id = $this->app->getId();
                $registration->jurisdiction_country = $country;
                $registration->jurisdiction_state = $state;
                $registration->registration_number = $this->data['registration_number'] ?? null;
                $registration->provider = $this->data['provider'] ?? 'manual';
                $registration->effective_from = $this->data['effective_from'] ?? null;
                $registration->effective_to = $this->data['effective_to'] ?? null;
                $registration->is_active = $this->data['is_active'] ?? true;
                $registration->metadata = $this->data['metadata'] ?? null;
                $registration->saveOrFail();

                return $registration;
            })
        );
    }
}
