<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Models\Integrations;
use Spatie\LaravelData\Data;

class IntegrationsCompany extends Data
{
    /**
     * @param array $config what the tenant submitted, credentials included; handed to the handler and
     *                      never stored on the row
     * @param array $persistedConfig the part the handler asked to keep on the row
     *                               (BaseIntegration::persistedConfig())
     */
    public function __construct(
        public AppInterface $app,
        public Integrations $integration,
        public CompanyInterface $company,
        public Regions $region,
        public array $config,
        public array $persistedConfig = []
    ) {
    }
}
