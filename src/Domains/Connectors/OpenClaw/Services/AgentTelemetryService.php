<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Services;

use App\GraphQL\Connector\OpenClaw\Subscriptions\AgentTelemetrySubscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenClaw\Enums\ConfigurationEnum;
use Kanvas\Connectors\OpenClaw\Events\AgentTelemetryUpdated;
use Kanvas\Connectors\OpenClaw\Jobs\CollectAgentTelemetryJob;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentDeploymentEvent;
use Nuwave\Lighthouse\Subscriptions\Contracts\BroadcastsSubscriptions;
use Throwable;

class AgentTelemetryService
{
    private const CACHE_TTL    = 180;
    private const INTERVAL_MS  = 60_000;

    public function start(): void
    {
        $this->collect();

        \Swoole\Timer::tick(self::INTERVAL_MS, fn () => $this->collect());
    }

    protected function collect(): void
    {
        // Refresh the worker lock so it doesn't expire between ticks (TTL slightly > interval).
        Cache::put('openclaw:telemetry:worker', getmypid(), 65);

        try {
            $deployments = AgentDeployment::where('status', 'running')
                ->with('machine')
                ->get();
        } catch (Throwable $e) {
            Log::warning('OpenClaw telemetry: failed to fetch deployments — ' . $e->getMessage());

            return;
        }

        foreach ($deployments as $deployment) {
            CollectAgentTelemetryJob::dispatch($deployment->id);
        }
    }

    public function collectForDeployment(AgentDeployment $deployment): void
    {
        try {
            // Ensure both relations are loaded — machine for SSH creds, agent for slug (tools lookup).
            $deployment->loadMissing(['machine', 'agent']);

            // One SSH connection, one exec channel — all commands run in a single shell pipeline.
            // This is far gentler on the server's sshd than opening separate channels per command.
            $ssh      = SshClient::fromMachine($deployment->machine);
            $sections = $ssh->getAllTelemetry();

            // Per-agent tools: extract unique tool names from this agent's own session JSONL files.
            // Falls back to the machine-level skills list when no session data exists yet.
            // Uses a separate exec call after getAllTelemetry so it doesn't inflate the pipeline timeout.
            $agentSlug     = $deployment->agent?->slug;
            $containerName = $deployment->container_name;
            $tools         = ($agentSlug && $containerName)
                ? $ssh->getAgentTools($containerName, $agentSlug)
                : null;

            $ssh->disconnect();

            $health        = $this->parseJson($sections['health']);
            $gatewayStatus = $this->parseJson($sections['gateway']);
            $memoryStats   = $this->parseMemoryStatus($sections['memory']);
            $version       = $this->parseVersion(trim($sections['version']));

            if ($health === null) {
                return;
            }

            $payload = $this->buildPayload(
                $deployment,
                $health,
                $gatewayStatus,
                $memoryStats,
                $version,
                $tools,
            );

            $this->detectAndStoreChanges($deployment, $payload);

            Cache::put('openclaw:telemetry:' . $deployment->id, $payload, self::CACHE_TTL);

            event(new AgentTelemetryUpdated($deployment, $payload, $payload['collected_at']));

            app(BroadcastsSubscriptions::class)->queueBroadcast(
                app(AgentTelemetrySubscription::class),
                'agentTelemetry',
                $payload
            );
        } catch (Throwable $e) {
            Log::warning("OpenClaw telemetry: failed for deployment {$deployment->id} — {$e->getMessage()}");

            try {
                AgentDeploymentEvent::record($deployment->id, AgentDeploymentEvent::AGENT_UNREACHABLE, [
                    'error' => $e->getMessage(),
                ]);
                $this->sendSlackAlert($deployment, AgentDeploymentEvent::AGENT_UNREACHABLE, $e->getMessage());
            } catch (Throwable) {
                // Don't let event recording swallow the original warning
            }
        }
    }

    /**
     * Compare a freshly-built telemetry payload against the previous cached snapshot and
     * write an AgentDeploymentEvent row for every meaningful state transition detected.
     *
     * Transitions tracked:
     *   gateway_reachable true  → false  : GATEWAY_DOWN
     *   gateway_reachable false → true   : GATEWAY_UP
     *   services healthy  true  → false  : HEALTH_FAIL
     *   services healthy  false → true   : HEALTH_RECOVER
     *   session_count increased          : SESSION_STARTED
     *
     * @param array<string, mixed> $newPayload
     */
    protected function detectAndStoreChanges(AgentDeployment $deployment, array $newPayload): void
    {
        /** @var array<string, mixed>|null $prev */
        $prev = Cache::get('openclaw:telemetry:' . $deployment->id);

        if ($prev === null) {
            // First successful collection — no baseline to diff against.
            return;
        }

        $prevGateway = (bool) ($prev['gateway_reachable'] ?? false);
        $newGateway  = (bool) ($newPayload['gateway_reachable'] ?? false);

        // ── Gateway reachability ──────────────────────────────────────────────
        if ($prevGateway && ! $newGateway) {
            AgentDeploymentEvent::record($deployment->id, AgentDeploymentEvent::GATEWAY_DOWN, [
                'previous' => $prevGateway,
                'current'  => $newGateway,
            ]);
            $this->sendSlackAlert($deployment, AgentDeploymentEvent::GATEWAY_DOWN);
        } elseif (! $prevGateway && $newGateway) {
            AgentDeploymentEvent::record($deployment->id, AgentDeploymentEvent::GATEWAY_UP, [
                'previous' => $prevGateway,
                'current'  => $newGateway,
            ]);
            $this->sendSlackAlert($deployment, AgentDeploymentEvent::GATEWAY_UP);
        }

        // ── Service health (gateway service + node service both running) ──────
        $prevRaw = isset($prev['raw']) ? json_decode((string) $prev['raw'], true) : null;
        $newRaw  = isset($newPayload['raw']) ? json_decode((string) $newPayload['raw'], true) : null;

        $prevHealthy = $this->isDeploymentHealthy($prevRaw, $prevGateway);
        $newHealthy  = $this->isDeploymentHealthy($newRaw, $newGateway);

        if ($prevHealthy && ! $newHealthy) {
            AgentDeploymentEvent::record($deployment->id, AgentDeploymentEvent::HEALTH_FAIL, [
                'gateway_reachable' => $newGateway,
                'gateway_service'   => $newRaw['gatewayService']['runtimeShort'] ?? null,
                'node_service'      => $newRaw['nodeService']['runtimeShort'] ?? null,
            ]);
        } elseif (! $prevHealthy && $newHealthy) {
            AgentDeploymentEvent::record($deployment->id, AgentDeploymentEvent::HEALTH_RECOVER, [
                'gateway_reachable' => $newGateway,
            ]);
        }

        // ── Session count increase ────────────────────────────────────────────
        $prevSessions = (int) ($prev['session_count'] ?? 0);
        $newSessions  = (int) ($newPayload['session_count'] ?? 0);

        if ($newSessions > $prevSessions) {
            AgentDeploymentEvent::record($deployment->id, AgentDeploymentEvent::SESSION_STARTED, [
                'previous_count' => $prevSessions,
                'current_count'  => $newSessions,
                'new_sessions'   => $newSessions - $prevSessions,
            ]);
        }
    }

    /**
     * Returns true when both the gateway and node services report a "running" status
     * and the gateway is reachable.
     *
     * @param array<string, mixed>|null $raw The decoded `raw` JSON field from the telemetry payload
     */
    protected function isDeploymentHealthy(?array $raw, bool $gatewayReachable): bool
    {
        if (! $gatewayReachable) {
            return false;
        }

        $gatewayRunning = str_contains((string) ($raw['gatewayService']['runtimeShort'] ?? ''), 'running');
        $nodeRunning    = str_contains((string) ($raw['nodeService']['runtimeShort'] ?? ''), 'running');

        return $gatewayRunning && $nodeRunning;
    }

    /**
     * Send gateway alert via Slack webhook and/or email, depending on what
     * is configured in app settings (key-value).
     *
     * App setting keys:
     *   openclaw_slack_webhook_url  → Slack incoming webhook URL
     *   openclaw_alert_email        → email address to notify
     *
     * Set them via: $app->set('openclaw_slack_webhook_url', 'https://hooks.slack.com/...')
     * Silently no-ops when neither key is configured.
     */
    protected function sendSlackAlert(AgentDeployment $deployment, string $eventType, string $detail = ''): void
    {
        try {
            $app  = Apps::find($deployment->apps_id);

            if (! $app) {
                return;
            }

            $agentName = $deployment->agent?->name ?? $deployment->container_name;

            $emoji = match ($eventType) {
                AgentDeploymentEvent::GATEWAY_DOWN      => ':red_circle:',
                AgentDeploymentEvent::AGENT_UNREACHABLE => ':warning:',
                AgentDeploymentEvent::GATEWAY_UP        => ':large_green_circle:',
                default                                  => ':information_source:',
            };

            $title = match ($eventType) {
                AgentDeploymentEvent::GATEWAY_DOWN      => 'Gateway offline',
                AgentDeploymentEvent::AGENT_UNREACHABLE => 'Agent unreachable',
                AgentDeploymentEvent::GATEWAY_UP        => 'Gateway back online',
                default                                  => $eventType,
            };

            $text = "{$emoji} *{$title}* — `{$agentName}`";

            if ($detail !== '') {
                $text .= "\n_{$detail}_";
            }

            // ── Slack ────────────────────────────────────────────────────────
            $webhookUrl = $app->get(ConfigurationEnum::SLACK_WEBHOOK_URL->value);

            if ($webhookUrl) {
                Http::post($webhookUrl, ['text' => $text]);
            }

            // ── Email ────────────────────────────────────────────────────────
            $alertEmail = $app->get(ConfigurationEnum::ALERT_EMAIL->value);

            if ($alertEmail) {
                Mail::raw(strip_tags(str_replace(['*', '`', '_'], '', $text)), function ($m) use ($alertEmail, $title, $agentName) {
                    $m->to($alertEmail)->subject("OpenClaw alert: {$title} — {$agentName}");
                });
            }
        } catch (Throwable $e) {
            Log::warning("OpenClaw telemetry: alert failed — {$e->getMessage()}");
        }
    }

    /**
     * Strip plugin warning lines that appear before/after the JSON blob and decode.
     *
     * @return array<string, mixed>|null
     */
    protected function parseJson(string $raw): ?array
    {
        $start = strpos($raw, '{');
        $end   = strrpos($raw, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode(substr($raw, $start, $end - $start + 1), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Parse the text output of `openclaw memory status`.
     *
     * Aggregates across all agent memory workspaces:
     *   - files/chunks: sum of indexed counts
     *   - vector/FTS: true if any workspace reports ready
     *   - cache: true + total entries if any workspace has it enabled
     *
     * Example lines matched:
     *   Indexed: 23/23 files · 26 chunks
     *   Vector: ready
     *   FTS: ready
     *   Embedding cache: enabled (28 entries)
     *
     * @return array{files: int, chunks: int, vector_enabled: bool, fts_available: bool, cache_enabled: bool, cache_entries: int}
     */
    protected function parseMemoryStatus(string $raw): array
    {
        $result = [
            'files'         => 0,
            'chunks'        => 0,
            'vector_enabled' => false,
            'fts_available'  => false,
            'cache_enabled'  => false,
            'cache_entries'  => 0,
        ];

        // Indexed: N/M files · K chunks  — sum N (indexed) and K across all workspaces
        if (preg_match_all('/Indexed:\s*(\d+)\/\d+\s+files\s+[·\x{00B7}]\s*(\d+)\s+chunks/u', $raw, $m)) {
            $result['files']  = array_sum(array_map('intval', $m[1]));
            $result['chunks'] = array_sum(array_map('intval', $m[2]));
        }

        // Vector: ready
        $result['vector_enabled'] = (bool) preg_match('/^Vector:\s+ready/m', $raw);

        // FTS: ready
        $result['fts_available'] = (bool) preg_match('/^FTS:\s+ready/m', $raw);

        // Embedding cache: enabled (N entries)
        if (preg_match_all('/Embedding cache:\s+enabled\s+\((\d+)\s+entries?\)/i', $raw, $m)) {
            $result['cache_enabled'] = true;
            $result['cache_entries'] = array_sum(array_map('intval', $m[1]));
        }

        return $result;
    }

    /**
     * Extract a clean semver string from `openclaw --version` output.
     * "openclaw/1.2.3 linux-arm64 node-v20.x" → "1.2.3"
     * "1.2.3" → "1.2.3"
     */
    protected function parseVersion(string $raw): ?string
    {
        if ($raw === '' || $raw === 'unknown') {
            return null;
        }

        // Matches semver (1.2.3) and date-based versions (2026.3.12)
        if (preg_match('/(\d{4}\.\d+\.\d+|\d+\.\d+\.\d+(?:-[\w.]+)?)/', $raw, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @param array<string, mixed>      $health        openclaw health --json
     * @param array<string, mixed>|null $gatewayStatus openclaw gateway status --json --deep
     * @param array<string, mixed>      $memoryStats   parsed from openclaw memory status
     * @param string|null               $version       parsed from openclaw --version
     * @param string|null               $tools         JSON-encoded array of eligible skill names
     * @return array<string, mixed>
     */
    protected function buildPayload(
        AgentDeployment $deployment,
        array $health,
        ?array $gatewayStatus,
        array $memoryStats = [],
        ?string $version = null,
        ?string $tools = null,
    ): array {
        // --- health ----------------------------------------------------------
        /** @var array<string, mixed> $sessions */
        $sessions = $health['sessions'] ?? [];

        // Default model: first agent entry in the health heartbeat list
        $defaultModel = null;
        /** @var array<int, array<string, mixed>> $agents */
        $agents = is_array($health['agents'] ?? null) ? $health['agents'] : [];

        if (! empty($agents)) {
            $first        = reset($agents);
            $defaultModel = is_array($first) ? ($first['model'] ?? null) : null;
        }

        // --- gateway status --deep -------------------------------------------
        /** @var array<string, mixed> $gwRuntime */
        $gwRuntime        = $gatewayStatus['service']['runtime'] ?? [];
        $gatewayReachable = isset($gatewayStatus['rpc']['ok'])
            ? (bool) $gatewayStatus['rpc']['ok']
            : (bool) ($health['ok'] ?? false);
        $gatewayLatency   = isset($health['durationMs']) ? (int) $health['durationMs'] : null;
        $gatewayError     = $gatewayStatus['rpc']['error'] ?? null;

        // --- memory ----------------------------------------------------------
        $memFiles  = (int) ($memoryStats['files'] ?? 0);
        $memChunks = (int) ($memoryStats['chunks'] ?? 0);

        // --- raw merged object (matches frontend field paths) ----------------
        $raw = [
            'gateway'        => ['error' => $gatewayError],
            'gatewayService' => ['runtimeShort' => $gwRuntime['status'] ?? null],
            'sessions'       => ['defaults' => ['contextTokens' => null]],
            'memory'         => [
                'cache'  => [
                    'enabled' => (bool) ($memoryStats['cache_enabled'] ?? false),
                    'entries' => (int) ($memoryStats['cache_entries'] ?? 0),
                ],
                'fts'    => ['available' => (bool) ($memoryStats['fts_available'] ?? false)],
                'vector' => ['enabled'   => (bool) ($memoryStats['vector_enabled'] ?? false)],
            ],
            'securityAudit'  => null,
        ];

        return [
            'deployment_id'      => $deployment->id,
            'collected_at'       => now()->toISOString(),
            'runtime_version'    => $version,
            'gateway_reachable'  => $gatewayReachable,
            'gateway_latency_ms' => $gatewayLatency,
            'session_count'      => (int) ($sessions['count'] ?? 0),
            'default_model'      => $defaultModel,
            'memory_files'       => $memFiles,
            'memory_chunks'      => $memChunks,
            'os_label'           => null,
            'security_critical'  => 0,
            'security_warn'      => 0,
            'raw'                => json_encode($raw) ?: null,
            'tools'              => $tools,
        ];
    }
}
