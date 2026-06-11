<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Hermes\Services\HermesStateDbReaderService;
use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseCollectDeploymentUsageAction;
use Kanvas\Intelligence\AgentRuntime\SshClient as BaseSshClient;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Override;

/**
 * Hermes concrete — reads the runtime's own `sessions` table over SSH.
 *
 * Per the Hermes docs (developer-guide/session-storage), `sessions` carries
 * input_tokens / output_tokens / cache_* / reasoning_tokens and both
 * estimated_cost_usd and actual_cost_usd per session. That is the authoritative
 * source — the older `hermes insights --json` CLI was speculative and undocumented.
 * Reading the table directly gives real token counts AND real cost (so no
 * model_pricing lookup is needed downstream), and HermesStateDbReaderService
 * already owns the SSH + sqlite plumbing the transcript collector uses.
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
        /** @var SshClient $client */
        $date = $this->date !== null ? Carbon::parse($this->date) : Carbon::now();

        $usage = new HermesStateDbReaderService($client)
            ->aggregateDailyUsage($this->deployment, $date);

        return json_encode($usage, JSON_THROW_ON_ERROR);
    }

    #[Override]
    protected function parseUsageOutput(string $rawOutput): array
    {
        /** @var array<string, mixed>|null $usage */
        $usage = json_decode($rawOutput, true);

        if (! is_array($usage)) {
            return [
                'error' => 'Failed to parse Hermes sessions usage output',
                'raw_length' => strlen($rawOutput),
                'collected_at' => now()->toIso8601String(),
            ];
        }

        $model = isset($usage['model']) && $usage['model'] !== '' ? (string) $usage['model'] : null;

        return [
            'deployment_id' => $this->deployment->getId(),
            'container_name' => $this->deployment->container_name,
            'totals' => $usage['totals'] ?? [],
            'cost_usd' => (float) ($usage['cost_usd'] ?? 0),
            'total_sessions' => (int) ($usage['total_sessions'] ?? 0),
            'provider' => $model !== null ? self::inferLlmProvider($model) : null,
            'model' => $model,
            'collected_at' => now()->toIso8601String(),
        ];
    }
}
