<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Interfaces;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Regions\Models\Regions;

abstract class IntegrationInterfaces
{
    public function __construct(
        Apps $app,
        Companies $company,
        Regions $region,
        array $data
    ) {
    }

    abstract public function setup(): bool;
}
