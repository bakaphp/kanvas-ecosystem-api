<?php

declare(strict_types=1);

namespace Tests\Connectors\NetSuite\Fakes;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Closure;
use Kanvas\Connectors\NetSuite\Client;
use NetSuite\NetSuiteService;
use Override;
use ReflectionProperty;

/**
 * The real Client with the SOAP transport and the Redis rate-limit slots taken out. Swapped into a
 * service's protected `$client` with `into()`, the same reflection seam PullNetSuiteProductPriceActionTest uses.
 */
class FakeNetSuiteClient extends Client
{
    public function __construct(
        AppInterface $app,
        CompanyInterface $company,
        public readonly FakeNetSuiteSoapService $soap = new FakeNetSuiteSoapService(),
    ) {
        parent::__construct($app, $company);
    }

    #[Override]
    public function getService(): NetSuiteService
    {
        return $this->soap;
    }

    #[Override]
    public function executeWithRateLimit(Closure $operation): mixed
    {
        return $operation($this->soap);
    }

    public function into(object $service): object
    {
        new ReflectionProperty($service, 'client')->setValue($service, $this);

        return $service;
    }
}
