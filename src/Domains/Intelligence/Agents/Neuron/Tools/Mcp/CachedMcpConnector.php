<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Mcp;

use Kanvas\Connectors\Mcp\Actions\StartMcpAsyncJobAction;
use Kanvas\Connectors\Mcp\DataTransferObject\McpServerConfig;
use Kanvas\Connectors\Mcp\Support\McpToolName;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Models\Tool as CapabilityTool;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\Workflow\Models\Integrations;
use NeuronAI\MCP\CallableMcpTool;
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

    protected ?int $integrationId = null;

    /** @var list<string> remote tools that start a background job */
    protected array $asyncTools = [];

    protected ?string $sessionUuid = null;

    protected ?int $conversationUserId = null;

    private ?Agent $agent = null;

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
            'integrationId' => $this->integrationId,
            'asyncTools' => $this->asyncTools,
            'sessionUuid' => $this->sessionUuid,
            'conversationUserId' => $this->conversationUserId,
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
        $this->integrationId = $data['integrationId'] ?? null;
        $this->asyncTools = $data['asyncTools'] ?? [];
        $this->sessionUuid = $data['sessionUuid'] ?? null;
        $this->conversationUserId = $data['conversationUserId'] ?? null;
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

    /**
     * @param list<string> $asyncTools
     */
    public function forIntegration(int $integrationId, array $asyncTools = []): self
    {
        $this->integrationId = $integrationId;
        $this->asyncTools = $asyncTools;

        return $this;
    }

    /**
     * Where a job this turn starts reports back to. Without a conversation there is nowhere to resume,
     * so a job-starting tool answers the model with the vendor's own result instead.
     */
    public function withConversation(?string $sessionUuid, ?int $conversationUserId): self
    {
        $this->sessionUuid = $sessionUuid;
        $this->conversationUserId = $conversationUserId;

        return $this;
    }

    /**
     * A call Kanvas makes on the agent's behalf — a background job's status check — so it spends no turn
     * budget and writes no `mcp.tool.invoked`: a poll every few seconds would bury the agent's own calls.
     *
     * @param array<string, mixed> $arguments
     */
    public function callRemoteTool(string $remoteName, array $arguments): mixed
    {
        return parent::invokeTool(['name' => $remoteName], $arguments);
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

            $tools[] = $this->createTool([...$descriptor, 'name' => $publicName]);
        }

        return $tools;
    }

    /**
     * The parent's schema mapping flattens nested schemas into shapes Gemini rejects — see McpToolSchema.
     */
    #[Override]
    protected function createTool(array $item): ToolInterface
    {
        $tool = McpTool::make(
            name: $item['name'],
            description: $item['description'] ?? null,
            annotations: $item['annotations'] ?? [],
        )->setCallable(new CallableMcpTool(connector: $this, item: $item));

        foreach (new McpToolSchema()->properties((array) ($item['inputSchema'] ?? [])) as $property) {
            $tool->addProperty($property);
        }

        return $tool;
    }

    /**
     * The model calls the prefixed name and passes free-form objects as JSON strings; the server answers
     * only to its own name and expects the objects.
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
        $arguments = new McpToolSchema()->decodeArguments((array) ($item['inputSchema'] ?? []), $arguments);
        $arguments = $this->withVendorDefaults($item['name'], $arguments);

        $startedAt = microtime(true);

        try {
            return $this->handOffIfAsync($item['name'], parent::invokeTool($item, $arguments));
        } finally {
            $this->calls++;
            $this->elapsedMs += (microtime(true) - $startedAt) * 1000;
            $this->emitLedger($item['name'], $publicName);
        }
    }

    /**
     * A tool that starts a job and returns before it finishes (Browser Use's `run_session` runs for
     * minutes) would otherwise have the model poll its status tool until the per-turn run cap kills the
     * turn. Once the job is recorded the model is told to stop; the poll resumes it with the result.
     */
    protected function handOffIfAsync(string $remoteName, mixed $result): mixed
    {
        if ($this->integrationId === null || $this->agentId === null || ! in_array($remoteName, $this->asyncTools, true)) {
            return $result;
        }

        try {
            $agent = $this->agent();
            $integration = Integrations::query()->where('id', $this->integrationId)->first();

            $job = $agent instanceof Agent && $integration instanceof Integrations
                ? new StartMcpAsyncJobAction(
                    agent: $agent,
                    integration: $integration,
                    remoteToolName: $remoteName,
                    content: $result,
                    sessionUuid: $this->sessionUuid,
                    usersId: $this->conversationUserId,
                )->execute()
                : null;
        } catch (Throwable $e) {
            // The job did start on the vendor's side — hand the model what the vendor said rather than
            // losing it to a bookkeeping failure.
            report($e);

            return $result;
        }

        if ($job === null) {
            return $result;
        }

        return (string) json_encode([
            'status' => 'running_in_background',
            'job_id' => $job->external_id,
            'live_url' => $job->live_url,
            'message' => 'This job runs in the background and can take several minutes. Kanvas posts the live '
                . 'browser link into this conversation as soon as there is one, and will wake you here with '
                . 'the result when the job ends. Do not call the status tool to check on it. Tell the user '
                . 'it has started and end your turn.',
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Arguments the vendor's collector insists on — the workspace a Browser Use job must write to for
     * its files to outlive the sandbox. Only keys the model left out are filled, so an explicit choice
     * still wins, and a vendor without a collector is untouched.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    protected function withVendorDefaults(string $remoteName, array $arguments): array
    {
        if ($this->integrationId === null || ! in_array($remoteName, $this->asyncTools, true)) {
            return $arguments;
        }

        try {
            $agent = $this->agent();
            $integration = Integrations::query()->where('id', $this->integrationId)->first();

            if (! $agent instanceof Agent || ! $integration instanceof Integrations) {
                return $arguments;
            }

            $defaults = McpServerConfig::fromIntegration($integration)
                ->artifactCollector()
                ?->defaultArguments($agent, $integration, $remoteName) ?? [];

            foreach ($defaults as $key => $value) {
                $arguments[$key] ??= $value;
            }
        } catch (Throwable $e) {
            // A vendor call that cannot be prepared still runs — it just loses its files.
            report($e);
        }

        return $arguments;
    }

    /**
     * Resolved once per turn: the ledger and the async hand-off both need it on the same call.
     */
    protected function agent(): ?Agent
    {
        return $this->agent ??= Agent::query()->where('id', $this->agentId)->first();
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
            $agent = $this->agent();

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
