<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentUsageSnapshot;

class CollectDailyUsageAction
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
        $rawOutput = $ssh->getUsage();

        return AgentUsageSnapshot::updateOrCreate(
            [
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
                'snapshot_date' => $snapshotDate,
                'source' => 'openclaw',
            ],
            [
                'raw_output' => $rawOutput,
                'parsed_data' => $this->parseUsageOutput($rawOutput),
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseUsageOutput(string $output): array
    {
        $lines = explode("\n", trim($output));
        $providers = [];
        $currentProvider = null;

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '─') || str_starts_with($line, '=')) {
                continue;
            }

            if (preg_match('/^(\w[\w\s]+):\s*$/', $line, $matches)) {
                $currentProvider = trim($matches[1]);
                $providers[$currentProvider] = [];

                continue;
            }

            if ($currentProvider !== null && preg_match('/^\s*(.+?):\s+(.+)$/', $line, $matches)) {
                $providers[$currentProvider][trim($matches[1])] = trim($matches[2]);
            }
        }

        return [
            'providers' => $providers,
            'collected_at' => now()->toIso8601String(),
        ];
    }
}
