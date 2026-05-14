<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Kanvas\Intelligence\Agents\Models\AgentUsageSnapshot;

// Subclasses' parseUsageOutput() must return the normalized shape:
//   totals: array{input_tokens,output_tokens,cache_read,cache_write,total_tokens},
//   sessions: array<int, array{...per-session fields}>,
//   total_sessions: int, provider: ?string, model: ?string,
//   ...any other keys are persisted under `parsed_data`.
abstract class BaseCollectDeploymentUsageAction
{
    public function __construct(
        protected AgentDeployment $deployment,
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected ?string $date = null,
    ) {
    }

    abstract protected function createSshClient(AgentMachine $machine): SshClient;

    abstract protected function fetchRawUsage(SshClient $client): string;

    /** @return array<string, mixed> */
    abstract protected function parseUsageOutput(string $rawOutput): array;

    public function execute(): AgentUsageSnapshot
    {
        $snapshotDate = $this->date ?? now()->toDateString();

        $client = $this->createSshClient($this->deployment->machine);

        try {
            $rawOutput = $this->fetchRawUsage($client);
            $providerName = $client::makeProviderConfig()->providerName;
        } finally {
            $client->disconnect();
        }

        $parsed = $this->parseUsageOutput($rawOutput);

        /** @var array<string, mixed> $totals */
        $totals = $parsed['totals'] ?? [];

        return AgentUsageSnapshot::updateOrCreate(
            [
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
                'agent_deployment_id' => $this->deployment->getId(),
                'snapshot_date' => $snapshotDate,
                'source' => $providerName . '_docker',
            ],
            [
                'input_tokens' => (int) ($totals['input_tokens'] ?? 0),
                'output_tokens' => (int) ($totals['output_tokens'] ?? 0),
                'total_tokens' => (int) ($totals['total_tokens'] ?? 0),
                'cache_read_tokens' => (int) ($totals['cache_read'] ?? 0),
                'cache_write_tokens' => (int) ($totals['cache_write'] ?? 0),
                'provider' => $parsed['provider'] ?? null,
                'model' => $parsed['model'] ?? null,
                'total_sessions' => (int) ($parsed['total_sessions'] ?? 0),
                'raw_output' => $rawOutput,
                'parsed_data' => $parsed,
            ]
        );
    }

    protected static function inferLlmProvider(string $model): ?string
    {
        if ($model === '') {
            return null;
        }

        if (str_contains($model, '/')) {
            return explode('/', $model)[0];
        }

        return match (true) {
            str_starts_with($model, 'gemini') => 'google',
            str_starts_with($model, 'claude') => 'anthropic',
            str_starts_with($model, 'gpt'), str_starts_with($model, 'o1'), str_starts_with($model, 'o3') => 'openai',
            str_starts_with($model, 'llama') => 'meta',
            str_starts_with($model, 'mistral') => 'mistral',
            default => null,
        };
    }

    // Node.js prints deprecation warnings on stderr which get merged into our captured stdout
    // via `2>&1`, ahead of the JSON. Strip everything before the first `{` so json_decode works.
    protected function stripNodeWarnings(string $output): string
    {
        $lines = explode("\n", $output);
        $jsonLines = [];
        $jsonStarted = false;

        foreach ($lines as $line) {
            if (! $jsonStarted && str_starts_with(trim($line), '{')) {
                $jsonStarted = true;
            }

            if ($jsonStarted) {
                $jsonLines[] = $line;
            }
        }

        return implode("\n", $jsonLines);
    }
}
