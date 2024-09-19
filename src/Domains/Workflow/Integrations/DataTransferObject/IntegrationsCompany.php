<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Workflow\Models\Integrations;
use Spatie\LaravelData\Data;

class IntegrationsCompany extends Data
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public AppInterface $app,
        public Integrations $integration,
        public CompanyInterface $company,
        public Regions $region,
        public array $config
    ) {
    }
}
