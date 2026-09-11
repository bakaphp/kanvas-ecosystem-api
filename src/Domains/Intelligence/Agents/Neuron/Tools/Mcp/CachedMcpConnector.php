<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Mcp;

use Kanvas\Connectors\Mcp\Support\McpToolName;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Models\Tool as CapabilityTool;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use NeuronAI\MCP\McpConnector;
use NeuronAI\Tools\ToolInterface;
use Override;
use Throwable;

/**
 * An McpConnector that builds its tools from cached descriptors — the parent's `tools()` costs three
 * round trips before the model sees a tool — and meters what the model spends.
 *
 * The budget and the ledger live in `invokeTool()`, not a wrapper closure: a closure could not survive
 * the serialization that carries MCP tools across an interrupt.
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
     * The agent is the actor and the tenant: curated servers are platform-wide (apps_id 0), so the
     * capability row has no app of its own to emit under.
     */
    protected function emitLedger(string $remoteName, string $publicName): void
    {
        if ($this->toolId === null || $this->agentId === null) {
            return;
        }

        try {
            $agent = Agent::query()->where('id', $this->agentId)->first();

            if (! $agent instanceof Agent) {
                return;
            }

            new AppendEventAction(new EventData(
                app: $agent->app,
                company: $agent->company,
                sourceDomain: 'NervousSystem',
                eventType: 'mcp.tool.invoked',
                status: EventStatusEnum::INFO,
                sourceEntityType: CapabilityTool::class,
                sourceEntityId: $this->toolId,
                actorType: 'Agent',
                actorId: $agent->getId(),
                payload: [
                    'tool' => $publicName,
                    'remote_tool' => $remoteName,
                ],
            ))->execute();
        } catch (Throwable $e) {
            // Bookkeeping must never break a tool call the model is waiting on — reported, not swallowed.
            report($e);
        }
    }
}
