<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ShortIo\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Kanvas\Connectors\ShortIo\Client;
use Kanvas\Connectors\ShortIo\Enums\ConfigurationEnum;

/**
 * The company's own short.io wins, else the app's. Credentials resolve as a pair: a company key is
 * never sent with the app's domain.
 */
class ShortIoConfigurationService
{
    public static function client(AppInterface $app, ?CompanyInterface $company = null): ?Client
    {
        $companyKey = Str::trimToNull((string) $company?->get(ConfigurationEnum::API_KEY->value));
        if ($companyKey !== null) {
            return new Client($companyKey, Str::trimToNull((string) $company->get(ConfigurationEnum::DOMAIN->value)));
        }

        $appKey = Str::trimToNull((string) $app->get(ConfigurationEnum::APP_API_KEY->value));
        if ($appKey === null) {
            return null;
        }

        return new Client($appKey, Str::trimToNull((string) $app->get(ConfigurationEnum::APP_DOMAIN->value)));
    }

    public static function linkPageBaseUrl(AppInterface $app, ?CompanyInterface $company = null): string
    {
        $baseUrl = Str::trimToNull((string) $company?->get(ConfigurationEnum::LINK_PAGE_BASE_URL->value))
            ?? (string) $app->get(ConfigurationEnum::APP_LINK_PAGE_BASE_URL->value);

        return rtrim($baseUrl, '/');
    }
}
