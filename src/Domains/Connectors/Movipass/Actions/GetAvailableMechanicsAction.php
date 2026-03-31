<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Collection;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Enums\MechanicAvailabilityEnum;
use Kanvas\Connectors\Movipass\Enums\MovipassRolesEnum;
use Kanvas\Users\Models\Users;

class GetAvailableMechanicsAction
{
    public function __construct(
        protected readonly AppInterface $app,
        protected readonly ?Companies $providerCompany = null,
    ) {
    }

    public function execute(): Collection
    {
        // Scope pattern for this app — mechanics may be company-scoped (app_{id}_company_{cid})
        $appScopePrefix = 'app_' . $this->app->getId() . '_company_%';

        return Users::select('users.*')
            ->join(
                'users_associated_apps',
                'users_associated_apps.users_id',
                '=',
                'users.id'
            )
            ->join(
                'assigned_roles',
                'assigned_roles.entity_id',
                '=',
                'users.id'
            )
            ->join('roles', 'roles.id', '=', 'assigned_roles.role_id')
            ->where('users_associated_apps.apps_id', $this->app->getId())
            ->where('users_associated_apps.is_active', 1)
            ->where('roles.name', MovipassRolesEnum::OPERATOR->value)
            ->where('assigned_roles.entity_type', Users::class)
            ->where('assigned_roles.scope', 'like', $appScopePrefix)
            ->when(
                $this->providerCompany,
                fn ($q) => $q->where(
                    'users_associated_apps.companies_id',
                    $this->providerCompany->getId()
                )
            )
            ->groupBy('users.id')
            ->get()
            ->filter(
                fn (Users $user) => $user->get(CustomFieldEnum::MECHANIC_AVAILABILITY->value) === MechanicAvailabilityEnum::ACTIVO->value
            )
            ->values();
    }
}
