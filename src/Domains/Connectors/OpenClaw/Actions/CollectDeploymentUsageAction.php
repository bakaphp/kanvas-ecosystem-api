<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseCollectDeploymentUsageAction;
use Kanvas\Intelligence\AgentRuntime\SshClient as BaseSshClient;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Override;

/**
 * OpenClaw concrete — runs `openclaw status --usage --json` inside the container.
 *
 * The CLI returns a single JSON document with `sessions.recent[]` carrying per-session
 * token counts; we sum across recents to fill the `totals` block.
 */
class CollectDeploymentUsageAction extends BaseCollectDeploymentUsageAction
{
    #[Override]
    protected function createSshClient(AgentMachine $machine): BaseSshClient
    {
        return SshClient::fromMachine($machine);
    }

    #[Override]
    protected function fetchRawUsage(BaseSshClient $client): string
    {
        $providerConfig = $client::makeProviderConfig();

        return $client->exec(
            'docker exec ' . escapeshellarg($this->deployment->container_name)
            . ' ' . $providerConfig->mjsPath . ' status --usage --json 2>&1',
            60,
        );
    }

    #[Override]
    protected function parseUsageOutput(string $rawOutput): array
    {
        $cleanOutput = $this->stripNodeWarnings($rawOutput);

        /** @var array<string, mixed>|null $json */
        $json = json_decode($cleanOutput, true);

        if (! is_array($json)) {
            return [
                'error' => 'Failed to parse JSON output',
                'raw_length' => strlen($rawOutput),
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
                'provider' => self::inferLlmProvider($model),
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
        $primaryModel = $recent[0]['model'] ?? null;
        $primaryProvider = $recent[0]['provider'] ?? null;

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
            'provider' => $primaryProvider,
            'model' => $primaryModel,
            'collected_at' => now()->toIso8601String(),
        ];
    }
}
