<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Collection;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Enums\MechanicAvailabilityEnum;
use Kanvas\Users\Models\Users;

class GetAvailableMechanicsAction
{
    public function __construct(
        protected readonly AppInterface $app,
        protected readonly ?Companies $providerCompany = null,
        protected readonly array $excludeIds = [],
    ) {
    }

    public function execute(): Collection
    {
        return Users::select('users.*')
            ->join(
                'users_associated_apps',
                'users_associated_apps.users_id',
                '=',
                'users.id'
            )
            ->where('users_associated_apps.apps_id', $this->app->getId())
            ->where('users_associated_apps.is_active', 1)
            ->when(
                $this->providerCompany,
                fn ($q) => $q->where(
                    'users_associated_apps.companies_id',
                    $this->providerCompany->getId()
                )
            )
            ->when(
                $this->excludeIds !== [],
                fn ($q) => $q->whereNotIn('users.id', $this->excludeIds)
            )
            ->groupBy('users.id')
            ->get()
            ->filter(
                fn (Users $user) => $user->get(CustomFieldEnum::MECHANIC_AVAILABILITY->value) === MechanicAvailabilityEnum::ACTIVO->value
            )
            ->values();
    }
}
