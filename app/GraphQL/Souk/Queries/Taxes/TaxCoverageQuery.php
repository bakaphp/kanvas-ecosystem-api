<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Queries\Taxes;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Souk\Taxes\DataTransferObject\TaxAddress;
use Kanvas\Souk\Taxes\DataTransferObject\TaxCoverage;
use Kanvas\Souk\Taxes\Providers\TaxProviderResolver;

class TaxCoverageQuery
{
    use ResolvesActingContext;

    public function resolve(mixed $rootValue, array $request): TaxCoverage
    {
        $ctx = $this->actingContext();

        $address = new TaxAddress(
            country: $request['country_code'],
            state: $request['state_code'] ?? null,
            city: null,
            postalCode: null,
        );

        return new TaxProviderResolver($ctx->app)->coverage($address);
    }
}
