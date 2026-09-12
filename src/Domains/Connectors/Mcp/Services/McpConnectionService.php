<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Services;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Mcp\DataTransferObject\McpServerConfig;
use Kanvas\Connectors\Mcp\Enums\McpAuthEnum;
use Kanvas\Connectors\Mcp\Enums\McpConnectionStatusEnum;
use Kanvas\Connectors\Mcp\Transports\GuardedHttpMcpTransport;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Mcp\CachedMcpConnector;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Kanvas\NervousSystem\Capability\Models\AgentTool;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\Workflow\Models\Integrations;
use NeuronAI\MCP\McpTransportInterface;
use Throwable;

/**
 * One agent's connection to one MCP server: whether it is usable, the server's descriptor, and a
 * connector wired to the guarded transport.
 *
 * Its state lives on the agent's grant for the server's tool (`config.mcp`) — connecting grants the tool
 * and revoking it deletes the credential, so "is this agent connected" has one home.
 */
class McpConnectionService
{
    private ?McpServerConfig $config = null;

    /**
     * $transport is a test seam; production always uses the guarded transport.
     */
    public function __construct(
        private readonly Agent $agent,
        private readonly Integrations $integration,
        private readonly ?McpTransportInterface $transport = null,
    ) {
    }

    public function config(): McpServerConfig
    {
        return $this->config ??= McpServerConfig::fromIntegration($this->integration);
    }

    public function tool(): ?Tool
    {
        return Tool::query()
            ->where('tool_type', ToolTypeEnum::MCP->value)
            ->where('integrations_id', $this->integration->getId())
            ->fromAppOrGlobal($this->agent->app)
            ->active()
            ->first();
    }

    public function grant(): ?AgentTool
    {
        $tool = $this->tool();

        if ($tool === null) {
            return null;
        }

        return AgentTool::query()
            ->where('agent_id', $this->agent->getId())
            ->where('tool_id', $tool->getId())
            ->active()
            ->first();
    }

    /**
     * Checked before any cache read, so a revoked or failed connection is never served from a warm entry.
     */
    public function isEnabled(): bool
    {
        $grant = $this->grant();

        if ($grant === null) {
            return false;
        }

        $state = self::stateOf($grant);

        if (($state['status'] ?? null) === McpConnectionStatusEnum::FAILED->value) {
            return false;
        }

        // Nothing to look for on a `none` connection: its credential, if any, lives in the address.
        if (($state['auth'] ?? null) === McpAuthEnum::NONE->value) {
            return true;
        }

        return new McpCredentialService($this->agent, $this->integration)->rawToken() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function stateOf(?AgentTool $grant): array
    {
        $config = $grant !== null && is_array($grant->config) ? $grant->config : [];

        return is_array($config['mcp'] ?? null) ? $config['mcp'] : [];
    }

    public function recordConnected(McpAuthEnum $method): void
    {
        $this->writeState([
            'status' => McpConnectionStatusEnum::ACTIVE->value,
            'auth' => $method->value,
            'connected_at' => Carbon::now()->toIso8601String(),
            'last_error' => null,
        ]);
    }

    /**
     * Only this agent's grant — one agent's rejected credential never switches the server off for others.
     */
    public function markFailed(string $reason): void
    {
        try {
            $this->writeState([
                'status' => McpConnectionStatusEnum::FAILED->value,
                'last_error' => mb_substr($reason, 0, 500),
            ]);
        } catch (Throwable) {
            // Best effort: failing to record why must not break the turn that is already degrading.
        }
    }

    public function connector(): CachedMcpConnector
    {
        return new CachedMcpConnector([
            // A URL each connection supplies is read by the transport from the credential at send time,
            // like the token, so it never lands in a serialized payload.
            'transport' => $this->transport ?? new GuardedHttpMcpTransport(
                url: $this->config()->urlPerConnection ? null : $this->config()->url,
                agentsId: $this->agent->getId(),
                integrationsId: $this->integration->getId(),
                timeoutMs: $this->config()->timeoutMs,
                transport: $this->config()->transport->value,
                authQueryParam: $this->config()->authQueryParam,
            ),
        ]);
    }

    /**
     * A live `tools/list` minus the platform denylist — a connect or a cache miss, never a warm turn.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchDescriptors(): array
    {
        $config = $this->config();

        $tools = array_values(array_filter(
            $this->connector()->fetchRemoteDescriptors(),
            fn (array $tool): bool => ! $config->isExcluded((string) ($tool['name'] ?? '')),
        ));

        // Deterministic order: an unstable list rewrites the LLM prompt prefix every turn and throws away
        // the provider's prompt cache even when nothing changed.
        usort($tools, fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        return $tools;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function writeState(array $changes): void
    {
        $grant = $this->grant();

        if ($grant === null) {
            return;
        }

        $config = is_array($grant->config) ? $grant->config : [];
        $config['mcp'] = [
            ...self::stateOf($grant),
            ...$changes,
        ];

        $grant->config = $config;
        $grant->saveOrFail();
    }
}
