<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Users\Models\Users;

class SetMechanicServiceTypeAction
{
    public function __construct(
        protected readonly Users $mechanic,
        protected readonly string $serviceType,
    ) {
    }

    public function execute(): Users
    {
        $this->mechanic->set(CustomFieldEnum::MECHANIC_SERVICE_TYPE->value, trim($this->serviceType), isPublic: true);

        return $this->mechanic;
    }
}
