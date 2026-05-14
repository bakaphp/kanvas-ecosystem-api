<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Actions;

use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseCollectDeploymentUsageAction;
use Kanvas\Intelligence\AgentRuntime\SshClient as BaseSshClient;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Override;

/**
 * Hermes concrete — runs `hermes insights --json` inside the container.
 *
 * Per the Hermes docs (`hermes insights [--days N] [--source platform]`), the CLI emits
 * a JSON document with token, cost, and activity analytics. Schema details aren't
 * publicly documented, so this parser defends against the most likely shapes:
 *
 *  - `totals` / `summary` block at the root carrying input/output/cache token counts,
 *  - `sessions` array (either top-level or under `sessions.recent`),
 *  - per-session entries shaped similarly to the OpenClaw output.
 *
 * Any unrecognized fields land in `parsed_data` so we don't lose them between schema
 * revisions. Snapshot upsert never fails because of missing keys — it just records zeros.
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
        // `--days 1` keeps the payload tight; the snapshot is per-day anyway.
        return $client->exec(
            'docker exec ' . escapeshellarg($this->deployment->container_name)
            . ' hermes insights --days 1 --json 2>&1',
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
                'error' => 'Failed to parse Hermes insights JSON output',
                'raw_length' => strlen($rawOutput),
                'collected_at' => now()->toIso8601String(),
            ];
        }

        // Accept either a top-level `summary`/`totals` block or session-recent aggregation.
        $totals = $this->extractTotals($json);
        $sessions = $this->extractSessions($json);
        $primaryModel = $this->resolvePrimaryModel($json, $sessions);

        return [
            'deployment_id' => $this->deployment->getId(),
            'container_name' => $this->deployment->container_name,
            'runtime_version' => $json['runtimeVersion'] ?? $json['version'] ?? null,
            'total_sessions' => count($sessions),
            'totals' => $totals,
            'sessions' => $sessions,
            'provider' => $primaryModel !== null ? self::inferLlmProvider($primaryModel) : null,
            'model' => $primaryModel,
            'collected_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, int>
     */
    private function extractTotals(array $json): array
    {
        /** @var array<string, mixed> $candidate */
        $candidate = $json['totals']
            ?? $json['summary']
            ?? $json['usage']
            ?? [];

        // Hermes is documented to emit token/cost analytics; accept several common key spellings.
        return [
            'input_tokens' => (int) ($candidate['input_tokens'] ?? $candidate['inputTokens'] ?? 0),
            'output_tokens' => (int) ($candidate['output_tokens'] ?? $candidate['outputTokens'] ?? 0),
            'cache_read' => (int) ($candidate['cache_read'] ?? $candidate['cacheRead'] ?? 0),
            'cache_write' => (int) ($candidate['cache_write'] ?? $candidate['cacheWrite'] ?? 0),
            'total_tokens' => (int) ($candidate['total_tokens'] ?? $candidate['totalTokens'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<int, array<string, mixed>>
     */
    private function extractSessions(array $json): array
    {
        /** @var array<int, mixed> $raw */
        $raw = $json['sessions']['recent']
            ?? $json['sessions']
            ?? [];

        $details = [];

        foreach ($raw as $session) {
            if (! is_array($session)) {
                continue;
            }

            /** @var array<string, mixed> $session */
            $model = (string) ($session['model'] ?? '');
            $details[] = [
                'session_id' => $session['sessionId'] ?? $session['id'] ?? null,
                'agent_id' => $session['agentId'] ?? null,
                'provider' => self::inferLlmProvider($model),
                'model' => $model !== '' ? $model : null,
                'context_tokens' => (int) ($session['contextTokens'] ?? 0),
                'input_tokens' => (int) ($session['inputTokens'] ?? $session['input_tokens'] ?? 0),
                'output_tokens' => (int) ($session['outputTokens'] ?? $session['output_tokens'] ?? 0),
                'cache_read' => (int) ($session['cacheRead'] ?? $session['cache_read'] ?? 0),
                'cache_write' => (int) ($session['cacheWrite'] ?? $session['cache_write'] ?? 0),
                'total_tokens' => (int) ($session['totalTokens'] ?? $session['total_tokens'] ?? 0),
                'remaining_tokens' => (int) ($session['remainingTokens'] ?? 0),
                'percent_used' => (int) ($session['percentUsed'] ?? 0),
            ];
        }

        return $details;
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  array<int, array<string, mixed>>  $sessions
     */
    private function resolvePrimaryModel(array $json, array $sessions): ?string
    {
        $candidate = $json['model']
            ?? $json['default_model']
            ?? $json['defaultModel']
            ?? ($sessions[0]['model'] ?? null);

        return is_string($candidate) && $candidate !== '' ? $candidate : null;
    }
}
