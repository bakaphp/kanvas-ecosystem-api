<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Mercury\Client;

/**
 * Shared plumbing for every Mercury API service.
 *
 * `$client` is a test seam: pass one built on a Guzzle MockHandler to exercise the real request-building path
 * against canned responses. Production always leaves it null, which rebuilds the client per instance — never
 * cached in a static, because under Octane a worker outlives the request and would keep serving a rotated
 * token.
 */
abstract class MercuryApiService
{
    protected Client $client;

    public function __construct(
        protected readonly AppInterface $app,
        protected readonly CompanyInterface $company,
        ?Client $client = null,
    ) {
        $this->client = $client ?? new Client($this->app, $this->company);
    }
}
