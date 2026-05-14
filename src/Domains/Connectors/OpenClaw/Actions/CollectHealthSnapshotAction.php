<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentUsageSnapshot;

class CollectHealthSnapshotAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected ?string $date = null,
    ) {
    }

    public function execute(): AgentUsageSnapshot
    {
        $snapshotDate = $this->date ?? now()->toDateString();

        $ssh = new SshClient($this->app, $this->company);
        $rawOutput = $ssh->getHealth();

        /** @var array<string, mixed>|null $parsed */
        $parsed = json_decode($rawOutput, true);

        return AgentUsageSnapshot::updateOrCreate(
            [
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
                'snapshot_date' => $snapshotDate,
                'source' => 'openclaw_health',
            ],
            [
                'raw_output' => $rawOutput,
                'parsed_data' => $parsed ?? ['raw' => $rawOutput],
            ]
        );
    }
}
