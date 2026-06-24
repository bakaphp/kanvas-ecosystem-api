<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Services;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Reynolds\Enums\ConfigurationEnum;

class TenantResolver
{
    public static function fromSender(
        string $dealerNumber,
        string $storeNumber,
        string $areaNumber,
        AppInterface $app,
    ): ?Companies {
        // Companies are not scoped to a single app in this DB (no apps_id column),
        // so the (Dealer, Store, Area) tuple in companies_settings is what
        // identifies the tenant. The $app parameter is kept on the signature for
        // future use (e.g. extra filter if multi-app dealer support is added).
        unset($app);

        return Companies::query()
            ->notDeleted()
            ->where(self::settingExists(ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value, $dealerNumber))
            ->where(self::settingExists(ConfigurationEnum::REYNOLDS_STORE_NUMBER->value, $storeNumber))
            ->where(self::settingExists(ConfigurationEnum::REYNOLDS_AREA_NUMBER->value, $areaNumber))
            ->first();
    }

    private static function settingExists(string $name, string $value): callable
    {
        return function ($query) use ($name, $value) {
            $query->whereExists(function ($q) use ($name, $value) {
                $q->select(DB::raw(1))
                    ->from('companies_settings')
                    ->whereColumn('companies_settings.companies_id', 'companies.id')
                    ->where('companies_settings.name', $name)
                    ->where('companies_settings.value', $value)
                    ->where(function ($q) {
                        $q->where('companies_settings.is_deleted', 0)
                            ->orWhereNull('companies_settings.is_deleted');
                    });
            });
        };
    }
}
