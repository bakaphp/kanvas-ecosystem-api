<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Collection;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Enums\MechanicAvailabilityEnum;
use Kanvas\Users\Models\Users;

class GetAvailableMechanicsAction
{
    public function __construct(
        protected readonly array $excludeIds = [],
    ) {
    }

    public function execute(): Collection
    {
        return Users::query()
        ->select('users.*')
        ->join('user_config', 'user_config.users_id', '=', 'users.id')
        ->where('user_config.name', CustomFieldEnum::MECHANIC_AVAILABILITY->value)
        ->where('user_config.value', MechanicAvailabilityEnum::ACTIVO->value)
        ->where('users.is_deleted', 0)
        ->when(
            $this->excludeIds !== [],
            fn ($q) => $q->whereNotIn('users.id', $this->excludeIds)
        )
        ->get();
    }
}
