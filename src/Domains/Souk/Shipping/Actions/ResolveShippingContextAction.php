<?php

declare(strict_types=1);

namespace Kanvas\Souk\Shipping\Actions;

use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Regions\Services\RegionResolutionService;
use Kanvas\Souk\Services\B2BConfigurationService;
use Kanvas\Souk\Shipping\DataTransferObject\ShippingContext;

class ResolveShippingContextAction
{
    public function __construct(
        protected Apps $app,
        protected ?UserInterface $user = null,
        protected ?CompaniesBranches $branch = null,
    ) {
    }

    public function execute(): ?ShippingContext
    {
        $company = $this->resolveCompany();

        if ($company === null) {
            return null;
        }

        return new ShippingContext(
            $this->app,
            $company,
            new RegionResolutionService($this->app)->forCurrentRequestOrFail($company)
        );
    }

    public function executeOrFail(): ShippingContext
    {
        return $this->execute() ?? throw new ValidationException('No company found');
    }

    private function resolveCompany(): ?CompanyInterface
    {
        if ($this->user !== null) {
            return B2BConfigurationService::getConfiguredB2BCompany($this->app, $this->user->getCurrentCompany());
        }

        return $this->branch?->company;
    }
}
