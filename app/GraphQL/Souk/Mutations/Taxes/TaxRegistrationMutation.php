<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Taxes;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Souk\Taxes\Actions\CreateTaxRegistrationAction;
use Kanvas\Souk\Taxes\Actions\UpdateTaxRegistrationAction;
use Kanvas\Souk\Taxes\Models\TaxRegistration;

class TaxRegistrationMutation
{
    use ResolvesActingContext;

    public function create(mixed $rootValue, array $request): TaxRegistration
    {
        $ctx = $this->actingContext();

        return new CreateTaxRegistrationAction(
            $ctx->app,
            $request['input'],
        )->execute();
    }

    public function update(mixed $rootValue, array $request): TaxRegistration
    {
        $ctx = $this->actingContext();

        /** @var TaxRegistration $registration */
        $registration = TaxRegistration::getById((int) $request['id'], $ctx->app);

        return new UpdateTaxRegistrationAction(
            $registration,
            $request['input'],
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        $ctx = $this->actingContext();

        /** @var TaxRegistration $registration */
        $registration = TaxRegistration::getById((int) $request['id'], $ctx->app);
        $registration->delete();

        return true;
    }
}
