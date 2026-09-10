<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Exceptions\AuthenticationException;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use Tests\TestCase;

abstract class McpTestCase extends TestCase
{
    use DatabaseTransactions;

    /**
     * Both non-default connections the MCP path writes to. Anything written on a connection that is
     * not listed here COMMITS and survives the test, and the symptom shows up as the *next* test in
     * the file failing on data this one leaked.
     */
    protected array $connectionsToTransact = ['mysql', 'workflow', 'intelligence'];

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
        // authenticated one have to be the same or every "connected" assertion reads false.
        $this->actingAs($this->mcpUser, 'api');

        $this->mcpApp = app(Apps::class);
        $this->mcpCompany = $this->mcpUser->getCurrentCompany();
    }

    private function resolveSharedUser(): Users
    {
        if (self::$sharedUserId !== null) {
            $existing = Users::query()->where('id', self::$sharedUserId)->first();

            if ($existing instanceof Users) {
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

    /**
     * Every test gets its OWN integrations row, and the credential key is derived from that row's id.
     * That is deliberate: `HashTableTrait::set()` writes to Redis first and then upserts on
     * `ecosystem` — Redis is shared by every parallel process and rolls back never, so a fixed key
     * would leak a token across the whole run.
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
        $integration->config = ['token' => ['type' => 'text', 'required' => true]];
        $integration->metadata = [
            'kind' => 'mcp',
            'vendor' => 'fakevendor',
            'url' => 'https://mcp.example.test/mcp',
            'transport' => 'http',
            'auth' => 'bearer',
            'prefix' => 'fake',
            'exclude' => [],
            'timeout_ms' => 5000,
            ...$metadataOverrides,
        ];
        $integration->is_deleted = 0;
        $integration->saveOrFail();

        return $integration;
    }

    protected function enableForCompany(Integrations $integration, string $status = 'active'): IntegrationsCompany
    {
        $statusRow = Status::where('slug', $status === 'active' ? StatusEnum::ACTIVE->value : StatusEnum::FAILED->value)
            ->where('apps_id', 0)
            ->firstOrFail();

        $row = new IntegrationsCompany();
        $row->companies_id = $this->mcpCompany->getId();
        $row->integrations_id = $integration->getId();
        $row->status_id = $statusRow->getId();
        $row->region_id = Regions::query()->first()?->getId() ?? 1;
        $row->is_active = 1;
        $row->is_deleted = 0;
        $row->saveOrFail();

        return $row;
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
}
