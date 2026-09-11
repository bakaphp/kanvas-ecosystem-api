<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Exceptions\AuthenticationException;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mcp\Actions\ConnectMcpServerAction;
use Kanvas\Connectors\Mcp\Enums\McpAuthEnum;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Connectors\Mcp\Services\McpConnectionService;
use Kanvas\Connectors\Mcp\Services\McpCredentialService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Capability\Actions\SetAgentToolAction;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Kanvas\NervousSystem\Capability\Models\AgentTool;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;
use Kanvas\Workflow\Models\Integrations;
use NeuronAI\MCP\McpTransportInterface;
use Tests\Stubs\Connectors\Mcp\FakeMcpServer;
use Tests\TestCase;
use Throwable;

abstract class McpTestCase extends TestCase
{
    use DatabaseTransactions;

    /**
     * Every connection the MCP path writes to. Anything written on a connection that is not listed
     * here COMMITS and survives the test, and the symptom shows up as the *next* test in the file
     * failing on data this one leaked. `ecosystem` holds the agents' custom fields — the credentials —
     * and app settings; without it every run left credential rows behind for agents that no longer exist.
     */
    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'workflow', 'intelligence'];

    protected Apps $mcpApp;

    protected Companies $mcpCompany;

    protected Users $mcpUser;

    /**
     * One fixture user for the whole class. `TestCase::createUser()` draws from `fake()->email`, whose
     * pool is small enough to collide once a file creates a user per test — and the row outlives the
     * transaction, so the collision is a hard "Email has already been taken" rather than a reset.
     */
    private static ?int $sharedUserId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mcpUser = $this->resolveSharedUser();
        // Resolvers read the acting user's current company, so the fixture company and the
        // authenticated one have to be the same or every agent lookup misses.
        $this->actingAs($this->mcpUser, 'api');

        $this->mcpApp = app(Apps::class);
        $this->mcpCompany = $this->mcpUser->getCurrentCompany();
    }

    private function resolveSharedUser(): Users
    {
        if (self::$sharedUserId !== null) {
            $existing = Users::query()->where('id', self::$sharedUserId)->first();

            // Its company lives on `ecosystem`, which rolls back after every test, so the user can outlive
            // the company it was created with.
            if ($existing instanceof Users && $this->hasCompany($existing)) {
                return $existing;
            }
        }

        // `createUser()` draws from `fake()->email`, whose pool is small enough that three paratest
        // processes racing on the same run still collide even though each holds its own user. The
        // collision is random, so a retry gets a different address — cheaper and safer than
        // reimplementing RegisterUsersAction with a guaranteed-unique email.
        $lastFailure = null;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $user = $this->createUser();
                self::$sharedUserId = $user->getId();

                return $user;
            } catch (AuthenticationException $e) {
                $lastFailure = $e;
            }
        }

        throw $lastFailure;
    }

    private function hasCompany(Users $user): bool
    {
        try {
            $user->getCurrentCompany();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Every test gets its OWN integrations row, and the credential keys are derived from that row's id.
     * That is deliberate: a custom-field write lands in Redis first — shared by every parallel process
     * and never rolled back — so a fixed key would leak a token across the whole run.
     *
     * @param array<string, mixed> $metadataOverrides
     */
    protected function makeIntegration(array $metadataOverrides = []): Integrations
    {
        $integration = new Integrations();
        $integration->uuid = (string) Str::uuid();
        $integration->name = 'mcp_test_' . Str::random(12);
        $integration->handler = McpHandler::class;
        $integration->apps_id = 0;
        $integration->type = IntegrationTypeEnum::MCP->value;
        $integration->config = ['token' => ['type' => 'text', 'required' => true]];
        $integration->metadata = [
            'vendor' => 'fakevendor',
            'url' => 'https://mcp.example.test/mcp',
            'transport' => 'http',
            'auth_methods' => ['bearer'],
            'prefix' => 'fake',
            'exclude' => [],
            'timeout_ms' => 5000,
            ...$metadataOverrides,
        ];
        $integration->is_deleted = 0;
        $integration->saveOrFail();

        return $integration;
    }

    protected function makeMcpTool(Integrations $integration): Tool
    {
        $tool = new Tool();
        $tool->apps_id = 0;
        $tool->name = $integration->name;
        $tool->description = 'Fake MCP server for tests.';
        $tool->tool_type = ToolTypeEnum::MCP->value;
        $tool->handler = null;
        $tool->integrations_id = $integration->getId();
        $tool->frameworks = ['neuron'];
        $tool->version = '1.0.0';
        $tool->is_active = 1;
        $tool->is_deleted = 0;
        $tool->saveOrFail();

        return $tool;
    }

    /**
     * An agent type with no provider, so the grant skips the framework-compatibility check the way a
     * freshly configured agent does. Pass a ConversesWithCustomer handler for a customer-facing agent.
     */
    protected function makeAgent(?string $handler = null): Agent
    {
        $type = AgentType::factory()
            ->withAppId($this->mcpApp->getId())
            ->create(['handler' => $handler]);

        return Agent::factory()
            ->withAppId($this->mcpApp->getId())
            ->withCompanyId($this->mcpCompany->getId())
            ->create(['agent_type_id' => $type->getId()]);
    }

    /**
     * The production connect path end to end — grant, credential, probe, snapshot — with the server
     * faked over its transport.
     */
    protected function connectAgent(
        Agent $agent,
        Tool $tool,
        ?McpTransportInterface $transport = null,
        string $token = 'agent-token',
        ?string $serverUrl = null
    ): int {
        return new ConnectMcpServerAction(
            agent: $agent,
            tool: $tool,
            actor: $this->mcpUser,
            method: McpAuthEnum::BEARER,
            grant: [
                'token' => $token,
                'server_url' => $serverUrl,
            ],
            transport: $transport ?? FakeMcpServer::listing(FakeMcpServer::twoTools()),
        )->execute();
    }

    /**
     * A grant plus a stored credential that was never proven against the server — the state an agent
     * is in when its server cannot be reached.
     */
    protected function grantWithCredential(Agent $agent, Tool $tool, string $token = 'agent-token'): AgentTool
    {
        $grant = new SetAgentToolAction(
            agent: $agent,
            tool: $tool,
            enabled: true,
            actor: $this->mcpUser,
        )->execute();

        $this->credentials($agent, $tool->integration)->store($token);

        return $grant;
    }

    protected function grantFor(Agent $agent, Tool $tool): ?AgentTool
    {
        return AgentTool::query()
            ->where('agent_id', $agent->getId())
            ->where('tool_id', $tool->getId())
            ->where('is_active', 1)
            ->where('is_deleted', 0)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function connectionState(Agent $agent, Tool $tool): array
    {
        return McpConnectionService::stateOf($this->grantFor($agent, $tool));
    }

    protected function credentials(Agent $agent, Integrations $integration): McpCredentialService
    {
        return new McpCredentialService($agent, $integration);
    }
}
