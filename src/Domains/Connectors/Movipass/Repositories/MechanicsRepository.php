<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Enums\StateEnums;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Enums\MovipassRolesEnum;
use Kanvas\Users\Repositories\UserAppRepository;

class MechanicsRepository
{
    /**
     * Mechanics are app users holding one of the field roles — there is no mechanic table, so
     * availability and service type are read from user_config rather than from columns.
     */
    public static function query(
        AppInterface $app,
        ?int $companyId = null,
        ?string $availability = null,
        ?string $serviceType = null
    ): Builder {
        $mechanicRoles = [
            MovipassRolesEnum::ROADSIDE_ASSISTANCE_OPERATOR->value,
            MovipassRolesEnum::OPERATOR->value,
            MovipassRolesEnum::TRUCK_DRIVER->value,
        ];

        $query = UserAppRepository::getAllAppUsers($app)
            ->whereHas(
                'roles',
                fn ($q) => $q->whereIn('name', $mechanicRoles)
            );

        if ($companyId !== null) {
            $query->whereExists(function ($q) use ($companyId) {
                $q->select(DB::raw(1))
                    ->from('users_associated_company')
                    ->whereRaw('users_associated_company.users_id = users.id')
                    ->where('users_associated_company.companies_id', $companyId)
                    ->where('users_associated_company.is_deleted', StateEnums::NO->getValue());
            });
        }

        if ($availability !== null) {
            $query->whereExists(fn ($q) => self::whereUserConfig(
                $q,
                CustomFieldEnum::MECHANIC_AVAILABILITY->value,
                strtolower($availability)
            ));
        }

        if ($serviceType !== null) {
            $query->whereExists(fn ($q) => self::whereUserConfig(
                $q,
                CustomFieldEnum::MECHANIC_SERVICE_TYPE->value,
                $serviceType
            ));
        }

        return $query;
    }

    private static function whereUserConfig(mixed $query, string $name, string $value): void
    {
        $query->select(DB::raw(1))
            ->from('user_config')
            ->whereRaw('user_config.users_id = users.id')
            ->where('user_config.name', $name)
            ->where('user_config.value', $value);
    }
}
