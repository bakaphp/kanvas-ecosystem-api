<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\Traits;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\ClaudeAgent\Client;

/**
 * Every action takes an optional Client so tests can inject canned HTTP, and builds a real one from
 * the tenant otherwise. Built per call, never cached — see the Octane rule on {@see Client}.
 */
trait ResolvesClaudeClient
{
    protected function claudeClient(AppInterface $app, CompanyInterface $company): Client
    {
        return $this->client ?? new Client($app, $company);
    }
}
