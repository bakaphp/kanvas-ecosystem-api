<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;

class OrderProviderScopeService
{
    /**
     * `provider_company_id` is the only dimension that separates one provider's orders from
     * another's — orders live in the platform's main company and the provider is reached through
     * the `order_providers` pivot. Left as a plain caller-supplied filter, a provider that simply
     * omits it reads the whole app's revenue, so it is derived from the caller instead.
     *
     * Apps without `B2B_MAIN_COMPANY_ID` are not running the provider model at all; their orders
     * belong to the company that placed them and this scoping would filter everything out.
     *
     * The main company gets an empty filter rather than its own id on purpose: SyncOrderProvidersAction
     * excludes the platform company from the pivot, so scoping it to itself would return nothing.
     *
     * @param array<int, int|string> $requested
     *
     * @return array<int, int>
     */
    public static function resolve(
        AppInterface $app,
        CompanyInterface $company,
        bool $isAppOwner = false,
        array $requested = [],
    ): array {
        $requested = array_values(array_map('intval', $requested));

        $mainCompanyId = $app->get('B2B_MAIN_COMPANY_ID');

        if ($mainCompanyId === null) {
            return $requested;
        }

        $currentCompanyId = (int) $company->getId();

        if ($isAppOwner || $currentCompanyId === (int) $mainCompanyId) {
            return $requested;
        }

        return [$currentCompanyId];
    }
}
