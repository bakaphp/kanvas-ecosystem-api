<?php

declare(strict_types=1);

namespace Kanvas\Connectors\AgentRuntime\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\AgentRuntime\SshClient;

class GetHealthAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
    ) {
    }

    public function execute(): string
    {
        $ssh = new SshClient($this->app, $this->company);

        return $ssh->getHealth();
    }
}
