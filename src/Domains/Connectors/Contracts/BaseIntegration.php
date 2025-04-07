<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Contracts;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Regions\Models\Regions;

abstract class BaseIntegration
{
    public function __construct(
        public Apps $app,
        public Companies $company,
        public Regions $region,
        public array $data
    ) {
    }

    /**
     * setup the connection
     * test the integration connection
     */
    abstract public function setup(): bool;
}
