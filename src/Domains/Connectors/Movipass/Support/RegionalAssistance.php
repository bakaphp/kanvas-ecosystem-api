<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Support;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Models\Order;

/**
 * The Asistencia Regional (El Salvador) operating model — tariff-sheet pricing, cabin-billed
 * additional charges and the payment guard — runs beside the roadside flow other tenants already
 * use, and must never change it. Every piece of that model asks this one gate first.
 *
 * The company setting wins over the app setting so one country can be switched on inside an app
 * that serves several.
 */
final class RegionalAssistance
{
    public static function isEnabled(AppInterface $app, ?CompanyInterface $company): bool
    {
        return (bool) self::setting($app, $company, ConfigurationEnum::ROADSIDE_REGIONAL_ASSISTANCE);
    }

    public static function isEnabledFor(Order $order): bool
    {
        return self::isEnabled($order->app, $order->company);
    }

    public static function tariffs(AppInterface $app, ?CompanyInterface $company): RoadsideTariffSchedule
    {
        $overrides = self::setting($app, $company, ConfigurationEnum::ROADSIDE_TARIFFS);

        return RoadsideTariffSchedule::fromArray(is_array($overrides) ? $overrides : []);
    }

    public static function tariffsFor(Order $order): RoadsideTariffSchedule
    {
        return self::tariffs($order->app, $order->company);
    }

    private static function setting(AppInterface $app, ?CompanyInterface $company, ConfigurationEnum $key): mixed
    {
        $companyValue = $company instanceof Companies ? $company->get($key->value) : null;

        return $companyValue ?? $app->get($key->value);
    }
}
