<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Mcp;

use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mcp\Services\McpToolCacheService;
use Kanvas\NervousSystem\Capability\Models\Tool as CapabilityTool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use Override;
use Throwable;

/**
 * One granted MCP server, presented to Neuron as a toolkit.
 *
 * Composition rather than extending McpConnector: `only()`/`exclude()` return `McpConnector` on the
 * parent and `ToolkitInterface` on the interface, and no class can satisfy both signatures.
 *
 * Neuron expands toolkits lazily at bootstrap and folds `guidelines()` into the system prompt, so the
 * 1-to-N expansion and the per-server framing both come for free — which is the reason MCP is wrapped
 * as a toolkit at all instead of resolved to a flat tool list.
 */
class RemoteMcpToolkit implements ToolkitInterface
{
    /** @var list<ToolInterface>|null */
    private ?array $resolved = null;

    private ?CachedMcpConnector $connector = null;

    private ?McpToolCacheService $cache = null;

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly CapabilityTool $tool,
    ) {
    }

    /**
     * Never throws. A dead vendor costs the agent one toolset, not the turn — so every failure path
     * here degrades to an empty list and a log line.
     *
     * @return list<ToolInterface>
     */
    #[Override]
    public function tools(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $this->resolved = [];

        try {
            $integration = $this->tool->integration;

            if ($integration === null) {
                return $this->resolved;
            }

            $cache = $this->cache();

            if (! $cache->connection()->isEnabled()) {
                return $this->resolved;
            }

            $descriptors = $cache->descriptors();

            if ($descriptors === []) {
                return $this->resolved;
            }

            $this->resolved = $this->connector()->toolsFromDescriptors(
                $descriptors,
                $cache->connection()->config()->prefix
            );
        } catch (Throwable $e) {
            Log::warning('MCP toolkit resolution failed', [
                'tool_id' => $this->tool->getId(),
                'companies_id' => $this->company->getId(),
                'error' => $e->getMessage(),
            ]);

            $this->resolved = [];
        }

        return $this->resolved;
    }

    /**
     * Neuron titles this block with the toolkit's class short name, which is identical for every MCP
     * server — so the server's own name has to lead the text or three granted servers render as three
     * indistinguishable `# RemoteMcpToolkit` headings and the grouping is worthless to the model.
     */
    #[Override]
    public function guidelines(): ?string
    {
        try {
            $config = $this->cache()->connection()->config();
            $vendor = $config->vendor ?? (string) $this->tool->name;

            return sprintf(
                '%s (via MCP). Tools below are prefixed `%s__` and act on the live %s account this company connected. Prefer reading before writing, and never invent identifiers — look them up first.',
                ucfirst($vendor),
                $config->prefix,
                $vendor
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The platform denylist in `integrations.metadata` is the real filter and is applied before the
     * descriptors are ever cached. These exist to satisfy the interface; Neuron calls neither on a
     * toolkit it did not build itself.
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

    public function callCount(): int
    {
        return $this->connector?->callCount() ?? 0;
    }

    public function elapsedMs(): int
    {
        return $this->connector?->elapsedMs() ?? 0;
    }

    private function connector(): CachedMcpConnector
    {
        return $this->connector ??= $this->cache()
            ->connection()
            ->connector()
            ->withLedgerContext($this->tool->getId(), $this->tool->agents_id);
    }

    private function cache(): McpToolCacheService
    {
        // Neuron calls guidelines() and tools() in the same bootstrap, and connector() again on the
        // first invoke — rebuilding the service each time re-parses the descriptor and re-runs the
        // isEnabled() lookup for no gain.
        return $this->cache ??= new McpToolCacheService(
            app: $this->app,
            company: $this->company,
            integration: $this->tool->integration,
            toolVersion: (string) ($this->tool->version ?? '1.0.0'),
        );
    }
}
