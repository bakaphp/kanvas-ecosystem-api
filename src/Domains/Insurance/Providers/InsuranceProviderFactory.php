<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Providers;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use InvalidArgumentException;
use Kanvas\Companies\Models\Companies;
use Kanvas\Insurance\Contracts\InsuranceProviderInterface;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Souk\Orders\Models\Order;
use Throwable;

class InsuranceProviderFactory
{
    /**
     * Providers are registered in the service container as "insurance_provider.{name}".
     *
     * @throws InvalidArgumentException when no provider is registered for the given name
     */
    public static function make(string $providerName, AppInterface $app, CompanyInterface $company): InsuranceProviderInterface
    {
        $binding = "insurance_provider.{$providerName}";

        if (! app()->bound($binding)) {
            throw new InvalidArgumentException("No insurance provider registered for '{$providerName}'.");
        }

        return app($binding, ['app' => $app, 'company' => $company]);
    }

    /**
     * Resolve the provider that already owns this order. Everything past the quote
     * goes through here, so no mutation past `insuranceQuote` needs to know — or
     * let the client choose — which insurer it is talking to.
     */
    public static function forOrder(Order $order): InsuranceProviderInterface
    {
        $providerName = (string) $order->get(InsuranceCustomFieldEnum::PROVIDER->value);

        if ($providerName === '') {
            throw new InvalidArgumentException('Order has no insurance provider — quote it first.');
        }

        return self::make($providerName, $order->app, $order->company);
    }

    /**
     * The acting company is rarely the one holding the credentials: in the
     * marketplace shape (.planning/movipass/features/04_multi_company_support) a
     * client company browses, the insurer owns the products, and the platform holds
     * the contract with the insurer. `forOrder` needs no such indirection — orders
     * belong to the platform by design.
     */
    public static function forQuoting(
        AppInterface $app,
        CompanyInterface $actingCompany,
        ?string $requested = null
    ): InsuranceProviderInterface {
        $company = self::credentialCompany($app, $actingCompany);

        return self::make(self::resolveName($company, $requested), $app, $company);
    }

    /**
     * The platform when the app declares one, otherwise whoever is acting — which
     * keeps single-tenant apps working unchanged. `B2B_MAIN_COMPANY_ID` is the
     * established key (`RoleBasedProductBuilder` scopes the marketplace by it);
     * don't add an insurance-specific one.
     */
    public static function credentialCompany(AppInterface $app, CompanyInterface $actingCompany): CompanyInterface
    {
        $platformCompanyId = (int) ($app->get('B2B_MAIN_COMPANY_ID') ?? 0);

        if ($platformCompanyId === 0 || $platformCompanyId === $actingCompany->getId()) {
            return $actingCompany;
        }

        try {
            return Companies::getById($platformCompanyId);
        } catch (Throwable) {
            throw new InvalidArgumentException(
                'B2B_MAIN_COMPANY_ID points at company ' . $platformCompanyId . ', which does not exist.'
            );
        }
    }

    /**
     * Provider to quote with: explicit choice wins, then the company default.
     */
    public static function resolveName(CompanyInterface $company, ?string $requested = null): string
    {
        $name = $requested ?: (string) $company->get(InsuranceCustomFieldEnum::PROVIDER->value);

        if ($name === '') {
            throw new InvalidArgumentException(
                'No insurance provider given and none configured for this company.'
            );
        }

        return $name;
    }
}
