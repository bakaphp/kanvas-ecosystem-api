<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Mcp;

use Kanvas\Connectors\Mcp\Support\McpToolName;
use Kanvas\NervousSystem\Capability\Models\Tool as CapabilityTool;
use NeuronAI\MCP\McpConnector;
use NeuronAI\Tools\ToolInterface;
use Override;
use Throwable;

/**
 * An McpConnector that builds its tools from a cached descriptor list instead of dialling the server,
 * and that meters what it spends.
 *
 * The parent's `tools()` always goes to the network, because it calls `client()` — and constructing
 * the client is `connect()` + `initialize()` + `notifications/initialized`, then `listTools()`, i.e.
 * three HTTP round trips before the LLM sees a single tool. On a warm turn we already know the answer,
 * so `toolsFromDescriptors()` maps the stored payload through the parent's own `createTool()` and
 * touches no socket. The client is still built lazily if the model actually invokes something.
 *
 * Everything the model calls funnels through `invokeTool()`, which is why the per-turn budget and the
 * ledger emission live here rather than in a wrapper closure — a closure could not survive the
 * serialization that lets MCP tools cross an interrupt.
 */
class CachedMcpConnector extends McpConnector
{
    public const int DEFAULT_MAX_CALLS_PER_TURN = 15;

    public const int DEFAULT_MAX_MS_PER_TURN = 60000;

    /** @var array<string, string> public tool name => the name the server published */
    protected array $nameMap = [];

    protected int $calls = 0;

    protected float $elapsedMs = 0.0;

    protected int $maxCalls = self::DEFAULT_MAX_CALLS_PER_TURN;

    protected int $maxMs = self::DEFAULT_MAX_MS_PER_TURN;

    protected ?int $toolId = null;

    protected ?int $agentId = null;

    #[Override]
    public function __serialize(): array
    {
        return [
            ...parent::__serialize(),
            'nameMap' => $this->nameMap,
            'calls' => $this->calls,
            'elapsedMs' => $this->elapsedMs,
            'maxCalls' => $this->maxCalls,
            'maxMs' => $this->maxMs,
            'toolId' => $this->toolId,
            'agentId' => $this->agentId,
        ];
    }

    #[Override]
    public function __unserialize(array $data): void
    {
        parent::__unserialize($data);
        $this->nameMap = $data['nameMap'] ?? [];
        $this->calls = $data['calls'] ?? 0;
        $this->elapsedMs = $data['elapsedMs'] ?? 0.0;
        $this->maxCalls = $data['maxCalls'] ?? self::DEFAULT_MAX_CALLS_PER_TURN;
        $this->maxMs = $data['maxMs'] ?? self::DEFAULT_MAX_MS_PER_TURN;
        $this->toolId = $data['toolId'] ?? null;
        $this->agentId = $data['agentId'] ?? null;
    }

    public function withBudget(int $maxCalls, int $maxMs): self
    {
        $this->maxCalls = $maxCalls;
        $this->maxMs = $maxMs;

        return $this;
    }

    /**
     * Ids, not models — the connector serializes, and an Eloquent model in that payload is how you get
     * an uninitialized typed property on the far side.
     */
    public function withLedgerContext(?int $toolId, ?int $agentId): self
    {
        $this->toolId = $toolId;
        $this->agentId = $agentId;

        return $this;
    }

    public function callCount(): int
    {
        return $this->calls;
    }

    public function elapsedMs(): int
    {
        return (int) round($this->elapsedMs);
    }

    /**
     * A live `tools/list`. Only the cache-miss and `setup()` paths should reach this.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchRemoteDescriptors(): array
    {
        return array_values($this->client()->listTools());
    }

    /**
     * @param  list<array<string, mixed>>  $descriptors
     * @return list<ToolInterface>
     */
    public function toolsFromDescriptors(array $descriptors, string $prefix): array
    {
        $tools = [];

        foreach ($descriptors as $descriptor) {
            $remoteName = (string) ($descriptor['name'] ?? '');

            if ($remoteName === '') {
                continue;
            }

            $publicName = McpToolName::format($prefix, $remoteName);
            $this->nameMap[$publicName] = $remoteName;

            $tools[] = parent::createTool([...$descriptor, 'name' => $publicName]);
        }

        return $tools;
    }

    /**
     * The model calls the prefixed name; the server only answers to its own. Translating here rather
     * than at tool-build time means the parent's whole schema-mapping path is reused untouched.
     */
    #[Override]
    public function invokeTool(array $item, array $arguments): mixed
    {
        if ($this->budgetSpent()) {
            // Refusing by return value rather than by removing the tool: Neuron exposes no way to
            // withdraw a tool mid-turn, and the model reads this and answers with what it has.
            return 'MCP budget exhausted for this turn — no further external calls. Answer with what you have.';
        }

        $publicName = (string) ($item['name'] ?? '');
        $item['name'] = $this->nameMap[$publicName] ?? $publicName;

        $startedAt = microtime(true);

        try {
            return parent::invokeTool($item, $arguments);
        } finally {
            $this->calls++;
            $this->elapsedMs += (microtime(true) - $startedAt) * 1000;
            $this->emitLedger($item['name'], $publicName);
        }
    }

    protected function budgetSpent(): bool
    {
        return $this->calls >= $this->maxCalls || $this->elapsedMs >= (float) $this->maxMs;
    }

    /**
     * Emitted from the capability row, with the agent as actor — so the agent's own ledger memory
     * records what it did in an external system, not merely that a call happened.
     */
    protected function emitLedger(string $remoteName, string $publicName): void
    {
        if ($this->toolId === null) {
            return;
        }

        try {
            $tool = CapabilityTool::query()->where('id', $this->toolId)->first();

            $tool?->emitLedgerEvent('mcp.tool.invoked', payload: [
                'tool' => $publicName,
                'remote_tool' => $remoteName,
                'agent_id' => $this->agentId,
            ]);
        } catch (Throwable) {
            // Never let bookkeeping break a tool call the model is waiting on.
        }
    }
}
