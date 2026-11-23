<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Enums;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Kanvas\Exceptions\ValidationException;

enum ImportEntityEnum: string
{
    case PRODUCTS = 'products';
    case CUSTOMERS = 'customers';
    case STOCK = 'stock';

    public function savedSearchSetting(): ConfigurationEnum
    {
        return match ($this) {
            self::PRODUCTS => ConfigurationEnum::NET_SUITE_PRODUCTS_SAVED_SEARCH_ID,
            self::CUSTOMERS => ConfigurationEnum::NET_SUITE_CUSTOMERS_SAVED_SEARCH_ID,
            self::STOCK => ConfigurationEnum::NET_SUITE_STOCK_SAVED_SEARCH_ID,
        };
    }

    public function readsLocations(): bool
    {
        return $this !== self::CUSTOMERS;
    }

    public function statusKey(): string
    {
        return 'NET_SUITE_IMPORT_STATUS_' . strtoupper($this->value);
    }

    /**
     * Saved search ids belong to the NetSuite account, and the account is configured on the app, so
     * the company setting overrides an app-wide one. Stock has no fallback: only a saved search joined
     * to locations carries per-location quantities.
     */
    public function resolveSavedSearchId(AppInterface $app, CompanyInterface $company, ?string $override): ?string
    {
        $key = $this->savedSearchSetting()->value;
        $savedSearchId = Str::trimToNull($override)
            ?? Str::trimToNull((string) $company->get($key))
            ?? Str::trimToNull((string) $app->get($key));

        if ($savedSearchId === null && $this === self::STOCK) {
            throw new ValidationException(
                'A NetSuite stock import needs a saved search. Pass saved_search_id or set ' . $key . '.'
            );
        }

        return $savedSearchId;
    }
}
