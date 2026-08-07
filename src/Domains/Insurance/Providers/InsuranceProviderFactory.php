<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Providers;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use InvalidArgumentException;
use Kanvas\Insurance\Contracts\InsuranceProviderInterface;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Souk\Orders\Models\Order;

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
