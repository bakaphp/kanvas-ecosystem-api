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
        $providerCompany = $this->providerCompany;

        return Users::getByCustomFieldBuilder(
            CustomFieldEnum::MECHANIC_AVAILABILITY->value,
            MechanicAvailabilityEnum::ACTIVO->value
        )
        ->whereExists(
            fn ($q) => $q->selectRaw('1')
                ->from('users_associated_apps')
                ->whereColumn('users_associated_apps.users_id', 'users.id')
                ->where('users_associated_apps.is_active', 1)
        )
        ->when(
            $providerCompany,
            fn ($q) => $q->whereExists(
                fn ($sub) => $sub->selectRaw('1')
                    ->from('users_associated_apps')
                    ->whereColumn('users_associated_apps.users_id', 'users.id')
                    ->where('users_associated_apps.companies_id', $providerCompany->getId())
            )
        )
        ->when(
            $this->excludeIds !== [],
            fn ($q) => $q->whereNotIn('users.id', $this->excludeIds)
        )
        ->get();
    }
}
