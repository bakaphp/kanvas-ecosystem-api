<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Collection;

class IntegrationService
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
    }

    public function getIntegrationsByCompany(): Collection
    {
        return $this->company->integrations();
    }
}
