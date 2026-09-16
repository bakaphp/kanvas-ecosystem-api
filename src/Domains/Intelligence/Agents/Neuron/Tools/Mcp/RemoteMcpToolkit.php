<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Mcp;

use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Mcp\Services\McpConnectionService;
use Kanvas\Connectors\Mcp\Services\McpToolCacheService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Models\Tool as CapabilityTool;
use NeuronAI\MCP\McpTransportInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use Override;
use Throwable;

/**
 * One agent's connection to one MCP server, presented to Neuron as a toolkit — Neuron expands it lazily
 * into the server's tools and folds `guidelines()` into the system prompt.
 *
 * Composition rather than extending McpConnector: `only()`/`exclude()` return `McpConnector` on the
 * parent and `ToolkitInterface` on the interface, and no class can satisfy both signatures.
 */
class RemoteMcpToolkit implements ToolkitInterface
{
    /** @var list<ToolInterface>|null */
    private ?array $resolved = null;

    private ?CachedMcpConnector $connector = null;

    private ?McpToolCacheService $cache = null;

    /**
     * $transport is a test seam; production always uses the guarded transport.
     */
    public function __construct(
        private readonly Agent $agent,
        private readonly CapabilityTool $tool,
        private readonly ?McpTransportInterface $transport = null,
    ) {
    }

    /**
     * @return list<ToolInterface>
     */
    #[Override]
    public function tools(): array
    {
        return $this->resolved ??= $this->resolve();
    }

    /**
     * Neuron heads this block with the class short name — the same for every MCP server — so the
     * server's own name has to lead the text.
     */
    #[Override]
    public function guidelines(): ?string
    {
        try {
            $config = $this->cache()->connection()->config();
            $vendor = $config->vendor ?? $this->tool->name;

            return sprintf(
                '%s (via MCP). Tools below are prefixed `%s__` and act on your own %s account. Prefer reading before writing, and never invent identifiers — look them up first.',
                ucfirst($vendor),
                $config->prefix,
                $vendor
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The platform denylist in `integrations.metadata` is the real filter, applied before anything is
     * cached; Neuron never calls these on a toolkit it did not build.
     *
     * @param array<array-key, class-string> $classes
     */
    #[Override]
    public function exclude(array $classes): ToolkitInterface
    {
        return $this;
    }

    /**
     * @param array<array-key, class-string> $classes
     */
    #[Override]
    public function only(array $classes): ToolkitInterface
    {
        return $this;
    }

    #[Override]
    public function with(string $class, callable $callback): ToolkitInterface
    {
        return $this;
    }

    /**
     * Never throws: a dead vendor or a broken row costs the agent one toolset, not the turn.
     *
     * @return list<ToolInterface>
     */
    private function resolve(): array
    {
        try {
            if ($this->tool->integration === null || ! $this->cache()->connection()->isEnabled()) {
                return [];
            }

            $descriptors = $this->cache()->descriptors();

            return $descriptors === []
                ? []
                : $this->connector()->toolsFromDescriptors($descriptors, $this->cache()->connection()->config()->prefix);
        } catch (Throwable $e) {
            Log::warning('MCP toolkit resolution failed', [
                'tool_id' => $this->tool->getId(),
                'agents_id' => $this->agent->getId(),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function connector(): CachedMcpConnector
    {
        return $this->connector ??= $this->cache()
            ->connection()
            ->connector()
            ->withLedgerContext($this->tool->getId(), $this->agent->getId());
    }

    private function cache(): McpToolCacheService
    {
        return $this->cache ??= new McpToolCacheService(
            agent: $this->agent,
            integration: $this->tool->integration,
            toolVersion: $this->tool->version,
            connection: new McpConnectionService($this->agent, $this->tool->integration, $this->transport),
        );
    }
}
