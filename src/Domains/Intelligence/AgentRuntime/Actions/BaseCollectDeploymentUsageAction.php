<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Kanvas\Intelligence\Agents\Models\AgentUsageSnapshot;
use Kanvas\Intelligence\Agents\Services\ModelPricingCalculator;

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

        $inputTokens = (int) ($totals['input_tokens'] ?? 0);
        $outputTokens = (int) ($totals['output_tokens'] ?? 0);
        $cacheReadTokens = (int) ($totals['cache_read'] ?? 0);
        $cacheWriteTokens = (int) ($totals['cache_write'] ?? 0);
        $providerSlug = isset($parsed['provider']) ? (string) $parsed['provider'] : null;
        $model = isset($parsed['model']) ? (string) $parsed['model'] : null;

        // Use the runtime's own cost when it actually reports one. Hermes carries
        // estimated/actual_cost_usd but is often left unconfigured (both 0 on the
        // live box), so fall back to model_pricing whenever the reported cost is
        // 0 or missing — otherwise real token usage records as $0.
        $reportedCost = (float) ($parsed['cost_usd'] ?? 0);
        $costUsd = $reportedCost > 0.0
            ? $reportedCost
            : app(ModelPricingCalculator::class)->costFor(
                $providerSlug,
                $model,
                $inputTokens,
                $outputTokens,
                $cacheReadTokens,
                $cacheWriteTokens,
                Carbon::parse($snapshotDate),
            );

        return AgentUsageSnapshot::updateOrCreate(
            [
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
                'agent_deployment_id' => $this->deployment->getId(),
                'snapshot_date' => $snapshotDate,
                'source' => $providerName . '_docker',
            ],
            [
                'agent_id' => $this->deployment->agent_id,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => (int) ($totals['total_tokens'] ?? 0),
                'cache_read_tokens' => $cacheReadTokens,
                'cache_write_tokens' => $cacheWriteTokens,
                'cost_usd' => $costUsd,
                'provider' => $providerSlug,
                'model' => $model,
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
