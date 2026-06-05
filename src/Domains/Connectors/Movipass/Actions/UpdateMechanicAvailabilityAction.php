<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Enums\MechanicAvailabilityEnum;
use Kanvas\Users\Models\Users;

class UpdateMechanicAvailabilityAction
{
    public function __construct(
        protected readonly Users $mechanic,
        protected readonly MechanicAvailabilityEnum $status,
    ) {
    }

    public function execute(): bool
    {
        $this->mechanic->set(CustomFieldEnum::MECHANIC_AVAILABILITY->value, $this->status->value);

        return true;
    }
}
