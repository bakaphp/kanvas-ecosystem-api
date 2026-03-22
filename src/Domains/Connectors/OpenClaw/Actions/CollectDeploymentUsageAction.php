<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentUsageSnapshot;

/**
 * Collect usage data from a Docker-deployed OpenClaw agent via CLI.
 *
 * Runs `openclaw status --usage --json` inside the agent's container and stores
 * the result in AgentUsageSnapshot. The JSON output includes per-session token
 * counts (input, output, cacheRead, cacheWrite, total), model info, and context
 * window utilization.
 *
 * Key data extracted from `sessions.recent[]`:
 *  - inputTokens, outputTokens, totalTokens
 *  - cacheRead, cacheWrite
 *  - model, contextTokens, remainingTokens, percentUsed
 *  - sessionId, agentId
 */
class CollectDeploymentUsageAction
{
    public function __construct(
        protected AgentDeployment $deployment,
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected ?string $date = null,
    ) {
    }

    public function execute(): AgentUsageSnapshot
    {
        $snapshotDate = $this->date ?? now()->toDateString();

        $client = SshClient::fromMachine($this->deployment->machine);

        try {
            $rawOutput = $client->exec(
                'docker exec ' . escapeshellarg($this->deployment->container_name)
                . ' node /app/dist/index.js status --usage --json 2>&1',
                30,
            );
        } finally {
            $client->disconnect();
        }

        $parsed = $this->parseStatusOutput($rawOutput);

        /** @var array<string, int> $totals */
        $totals = $parsed['totals'] ?? [];

        /** @var array<int, array<string, mixed>> $sessions */
        $sessions = $parsed['sessions'] ?? [];

        $primaryModel = $sessions[0]['model'] ?? null;

        return AgentUsageSnapshot::updateOrCreate(
            [
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
                'agent_deployment_id' => $this->deployment->getId(),
                'snapshot_date' => $snapshotDate,
                'source' => 'openclaw_docker',
            ],
            [
                'input_tokens' => $totals['input_tokens'] ?? 0,
                'output_tokens' => $totals['output_tokens'] ?? 0,
                'total_tokens' => $totals['total_tokens'] ?? 0,
                'cache_read_tokens' => $totals['cache_read'] ?? 0,
                'cache_write_tokens' => $totals['cache_write'] ?? 0,
                'provider' => $sessions[0]['provider'] ?? null,
                'model' => $primaryModel,
                'total_sessions' => $parsed['total_sessions'] ?? 0,
                'raw_output' => $rawOutput,
                'parsed_data' => $parsed,
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseStatusOutput(string $output): array
    {
        $cleanOutput = $this->stripNodeWarnings($output);

        /** @var array<string, mixed>|null $json */
        $json = json_decode($cleanOutput, true);

        if (! is_array($json)) {
            return [
                'error' => 'Failed to parse JSON output',
                'raw_length' => strlen($output),
                'collected_at' => now()->toIso8601String(),
            ];
        }

        /** @var array<string, mixed> $sessions */
        $sessions = $json['sessions'] ?? [];
        /** @var array<int, array<string, mixed>> $recent */
        $recent = $sessions['recent'] ?? [];

        $totalInput = 0;
        $totalOutput = 0;
        $totalCacheRead = 0;
        $totalCacheWrite = 0;
        $totalTokens = 0;
        $sessionDetails = [];

        foreach ($recent as $session) {
            $inputTokens = (int) ($session['inputTokens'] ?? 0);
            $outputTokens = (int) ($session['outputTokens'] ?? 0);
            $cacheRead = (int) ($session['cacheRead'] ?? 0);
            $cacheWrite = (int) ($session['cacheWrite'] ?? 0);

            $totalInput += $inputTokens;
            $totalOutput += $outputTokens;
            $totalCacheRead += $cacheRead;
            $totalCacheWrite += $cacheWrite;
            $totalTokens += (int) ($session['totalTokens'] ?? 0);

            $model = (string) ($session['model'] ?? '');

            $sessionDetails[] = [
                'session_id' => $session['sessionId'] ?? null,
                'agent_id' => $session['agentId'] ?? null,
                'provider' => self::inferProvider($model),
                'model' => $model !== '' ? $model : null,
                'context_tokens' => (int) ($session['contextTokens'] ?? 0),
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'cache_read' => $cacheRead,
                'cache_write' => $cacheWrite,
                'total_tokens' => (int) ($session['totalTokens'] ?? 0),
                'remaining_tokens' => (int) ($session['remainingTokens'] ?? 0),
                'percent_used' => (int) ($session['percentUsed'] ?? 0),
            ];
        }

        /** @var array<string, mixed> $gateway */
        $gateway = $json['gateway'] ?? [];

        return [
            'deployment_id' => $this->deployment->getId(),
            'container_name' => $this->deployment->container_name,
            'runtime_version' => $json['runtimeVersion'] ?? null,
            'gateway_reachable' => $gateway['reachable'] ?? null,
            'total_sessions' => count($recent),
            'totals' => [
                'input_tokens' => $totalInput,
                'output_tokens' => $totalOutput,
                'cache_read' => $totalCacheRead,
                'cache_write' => $totalCacheWrite,
                'total_tokens' => $totalTokens,
            ],
            'sessions' => $sessionDetails,
            'collected_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Infer the provider from a model name (e.g. "gemini-3.1-pro" → "google").
     * Models may use full prefix ("google/gemini-3.1-pro") or short names.
     */
    private static function inferProvider(string $model): ?string
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

    /**
     * Strip Node.js deprecation warnings that appear before the JSON output.
     */
    private function stripNodeWarnings(string $output): string
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
