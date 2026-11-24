<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Brex\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Brex\Client;

abstract class BrexApiService
{
    protected Client $client;

    public function __construct(
        protected readonly AppInterface $app,
        protected readonly CompanyInterface $company,
    ) {
        $this->client = new Client($this->app, $this->company);
    }
}
