<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\OpenClaw\Events\AgentTelemetryUpdated;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Nuwave\Lighthouse\Subscriptions\Contracts\BroadcastsSubscriptions;
use App\GraphQL\Connector\OpenClaw\Subscriptions\AgentTelemetrySubscription;
use Throwable;

class AgentTelemetryService
{
    private const CACHE_TTL = 120;
    private const INTERVAL_MS = 30_000;

    public function start(): void
    {
        $this->collect();

        \Swoole\Timer::tick(self::INTERVAL_MS, fn () => $this->collect());
    }

    protected function collect(): void
    {
        try {
            $deployments = AgentDeployment::where('status', 'running')
                ->with('machine')
                ->get();
        } catch (Throwable $e) {
            Log::warning('OpenClaw telemetry: failed to fetch deployments — ' . $e->getMessage());

            return;
        }

        foreach ($deployments as $deployment) {
            $this->collectForDeployment($deployment);
        }
    }

    protected function collectForDeployment(AgentDeployment $deployment): void
    {
        try {
            $ssh = SshClient::fromMachine($deployment->machine);
            $json = $ssh->getHealth();
            $ssh->disconnect();

            /** @var array<string, mixed>|null $data */
            $data = json_decode($json, true);

            if (! is_array($data)) {
                return;
            }

            $payload = $this->buildPayload($deployment, $data);

            Cache::put('openclaw:telemetry:' . $deployment->id, $payload, self::CACHE_TTL);

            event(new AgentTelemetryUpdated($deployment, $payload, $payload['collected_at']));

            app(BroadcastsSubscriptions::class)->queueBroadcast(
                app(AgentTelemetrySubscription::class),
                'agentTelemetry',
                $payload
            );
        } catch (Throwable $e) {
            Log::warning("OpenClaw telemetry: failed for deployment {$deployment->id} — {$e->getMessage()}");
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function buildPayload(AgentDeployment $deployment, array $data): array
    {
        /** @var array<string, mixed> $gateway */
        $gateway = $data['gateway'] ?? [];
        /** @var array<string, mixed> $sessions */
        $sessions = $data['sessions'] ?? [];
        /** @var array<string, mixed> $defaults */
        $defaults = $sessions['defaults'] ?? [];
        /** @var array<string, mixed> $memory */
        $memory = $data['memory'] ?? [];
        /** @var array<string, mixed> $os */
        $os = $data['os'] ?? [];
        /** @var array<string, mixed> $securityAudit */
        $securityAudit = $data['securityAudit'] ?? [];
        /** @var array<string, mixed> $summary */
        $summary = $securityAudit['summary'] ?? [];

        return [
            'deployment_id' => $deployment->id,
            'collected_at' => now()->toISOString(),
            'runtime_version' => $data['runtimeVersion'] ?? null,
            'gateway_reachable' => (bool) ($gateway['reachable'] ?? false),
            'gateway_latency_ms' => isset($gateway['connectLatencyMs']) ? (int) $gateway['connectLatencyMs'] : null,
            'session_count' => (int) ($sessions['count'] ?? 0),
            'default_model' => $defaults['model'] ?? null,
            'memory_files' => (int) ($memory['files'] ?? 0),
            'memory_chunks' => (int) ($memory['chunks'] ?? 0),
            'os_label' => $os['label'] ?? null,
            'security_critical' => (int) ($summary['critical'] ?? 0),
            'security_warn' => (int) ($summary['warn'] ?? 0),
            'raw' => json_encode($data) ?: null,
        ];
    }
}
