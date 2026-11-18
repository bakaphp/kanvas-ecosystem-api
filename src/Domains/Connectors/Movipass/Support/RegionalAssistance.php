<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Support;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
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

    /**
     * The tariff in force at $at: the newest version whose effective date has passed, else the plain
     * override setting. Pricing passes the order's creation time, so a later change never reprices
     * an order already quoted under the old sheet.
     */
    public static function tariffs(
        AppInterface $app,
        ?CompanyInterface $company,
        ?CarbonInterface $at = null
    ): RoadsideTariffSchedule {
        $at ??= Carbon::now();
        $overrides = self::setting($app, $company, ConfigurationEnum::ROADSIDE_TARIFFS);

        foreach (array_reverse(self::tariffVersions($app, $company)) as $version) {
            if (Carbon::parse((string) $version['effective_from'])->lessThanOrEqualTo($at)) {
                $overrides = $version['tariffs'] ?? [];

                break;
            }
        }

        return RoadsideTariffSchedule::fromArray(is_array($overrides) ? $overrides : []);
    }

    public static function tariffsFor(Order $order): RoadsideTariffSchedule
    {
        return self::tariffs($order->app, $order->company, $order->created_at);
    }

    /**
     * Ordered by effective date. Versions are only ever appended, so the history of every price the
     * service ever charged stays readable.
     *
     * @return array<int, array{effective_from: string, tariffs: array, reason: string, users_id: int, created_at: string}>
     */
    public static function tariffVersions(AppInterface $app, ?CompanyInterface $company): array
    {
        $versions = self::setting($app, $company, ConfigurationEnum::ROADSIDE_TARIFF_VERSIONS);
        $versions = is_string($versions) ? json_decode($versions, true) : $versions;
        $versions = is_array($versions) ? array_values(array_filter($versions, static fn ($v) => isset($v['effective_from']))) : [];

        usort($versions, static fn (array $a, array $b) => strcmp((string) $a['effective_from'], (string) $b['effective_from']));

        return $versions;
    }

    /**
     * Writes where the tariffs are read from: the company when it carries its own Asistencia
     * Regional settings, the app otherwise.
     */
    public static function settingsOwner(AppInterface $app, ?CompanyInterface $company): AppInterface|CompanyInterface
    {
        if ($company instanceof Companies
            && ($company->get(ConfigurationEnum::ROADSIDE_REGIONAL_ASSISTANCE->value) !== null
                || $company->get(ConfigurationEnum::ROADSIDE_TARIFF_VERSIONS->value) !== null)
        ) {
            return $company;
        }

        return $app;
    }

    /**
     * @return array<string, float> minutes per SLA metric, only the ones the admin overrode
     */
    public static function slaThresholds(Order $order): array
    {
        $value = self::setting($order->app, $order->company, ConfigurationEnum::ROADSIDE_SLA_MINUTES);
        $value = is_string($value) ? json_decode($value, true) : $value;

        return is_array($value) ? array_map('floatval', array_filter($value, 'is_numeric')) : [];
    }

    public static function parameter(Order $order, ConfigurationEnum $key, int|float $default): float
    {
        $value = self::setting($order->app, $order->company, $key);

        return is_numeric($value) ? (float) $value : (float) $default;
    }

    private static function setting(AppInterface $app, ?CompanyInterface $company, ConfigurationEnum $key): mixed
    {
        $companyValue = $company instanceof Companies ? $company->get($key->value) : null;

        return $companyValue ?? $app->get($key->value);
    }
}
