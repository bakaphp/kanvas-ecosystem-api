<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Movipass\Builders;

use Baka\Enums\StateEnums;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Enums\MovipassRolesEnum;
use Kanvas\Users\Repositories\UserAppRepository;

class MechanicsBuilder
{
    public function build(mixed $root, array $args): Builder
    {
        $app = app(Apps::class);

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

        if (isset($args['company_id'])) {
            $companyId = (int) $args['company_id'];
            $query->whereExists(function ($q) use ($companyId) {
                $q->select(DB::raw(1))
                    ->from('users_associated_company')
                    ->whereRaw('users_associated_company.users_id = users.id')
                    ->where('users_associated_company.companies_id', $companyId)
                    ->where('users_associated_company.is_deleted', StateEnums::NO->getValue());
            });
        }

        if (isset($args['availability'])) {
            $availability = $args['availability'];
            $query->whereExists(function ($q) use ($availability) {
                $q->select(DB::raw(1))
                    ->from('user_config')
                    ->whereRaw('user_config.users_id = users.id')
                    ->where('user_config.name', CustomFieldEnum::MECHANIC_AVAILABILITY->value)
                    ->where('user_config.value', strtolower($availability));
            });
        }

        if (isset($args['service_type'])) {
            $serviceType = $args['service_type'];
            $query->whereExists(function ($q) use ($serviceType) {
                $q->select(DB::raw(1))
                    ->from('user_config')
                    ->whereRaw('user_config.users_id = users.id')
                    ->where('user_config.name', CustomFieldEnum::MECHANIC_SERVICE_TYPE->value)
                    ->where('user_config.value', $serviceType);
            });
        }

        return $query;
    }
}
