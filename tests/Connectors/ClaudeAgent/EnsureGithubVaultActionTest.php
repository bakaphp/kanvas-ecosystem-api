<?php

declare(strict_types=1);

namespace Tests\Connectors\ClaudeAgent;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\ClaudeAgent\Actions\EnsureGithubVaultAction;
use Kanvas\Connectors\ClaudeAgent\Enums\CustomFieldEnum;
use Kanvas\Connectors\ClaudeAgent\Services\AgentSpecBuilderService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Tests\Connectors\Traits\HasClaudeAgentConfiguration;
use Tests\TestCase;

/**
 * A mounted repo gives git but not the GitHub API — the proxy injects the token after the request
 * leaves the container, so `git push` works and `api.github.com` returns 401. The vault is what
 * closes that gap, by authenticating the GitHub MCP server with the PAT the agent already has.
 */
final class EnsureGithubVaultActionTest extends TestCase
{
    use DatabaseTransactions;
    use HasClaudeAgentConfiguration;

    /** Settings live on mysql; agents on intelligence. */
    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    private Apps $currentApp;
    private Companies $currentCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->currentCompany = static::$cachedUser->getCurrentCompany();
        $this->configureClaudeAgent($this->currentApp, $this->currentCompany);
    }

    private function agentWithRepo(?string $token = 'ghp_test'): Agent
    {
        $agent = $this->makeClaudeAgent($this->currentApp, $this->currentCompany);

        $agent->set(CustomFieldEnum::CLAUDE_ALLOWED_REPOS->value, [[
            'slug' => 'api',
            'url' => 'https://github.com/bakaphp/kanvas-ecosystem-api',
        ]]);

        if ($token !== null) {
            $agent->set(CustomFieldEnum::CLAUDE_GITHUB_TOKEN->value, $token);
        }

        return $agent;
    }

    public function testItCreatesTheVaultAndStoresItsIdOnTheAgent(): void
    {
        $agent = $this->agentWithRepo();

        $client = $this->claudeAgentClientReturning($this->currentApp, $this->currentCompany, [
            $this->claudeAgentJsonResponse(200, ['id' => 'vlt_01']),
            $this->claudeAgentJsonResponse(200, ['id' => 'vcrd_01']),
        ]);

        $this->assertSame('vlt_01', new EnsureGithubVaultAction($agent, $client)->execute());
        $this->assertSame('vlt_01', $agent->get(CustomFieldEnum::CLAUDE_VAULT_ID->value));
    }

    /**
     * This runs on the chat path, so the settled case must not cost an HTTP call — an exhausted mock
     * queue would throw if it did.
     */
    public function testAnUnchangedTokenMakesNoHttpCall(): void
    {
        $agent = $this->agentWithRepo();
        $agent->set(CustomFieldEnum::CLAUDE_VAULT_ID->value, 'vlt_01');
        $agent->set(CustomFieldEnum::CLAUDE_VAULT_FINGERPRINT->value, hash('sha256', 'ghp_test'));

        $client = $this->claudeAgentClientReturning($this->currentApp, $this->currentCompany, []);

        $this->assertSame('vlt_01', new EnsureGithubVaultAction($agent, $client)->execute());
    }

    /**
     * A rotated PAT must reach the vault, or the agent keeps 401ing with a credential that looks
     * configured — the exact failure this whole path exists to remove.
     */
    public function testARotatedTokenUpdatesTheExistingCredential(): void
    {
        $agent = $this->agentWithRepo(token: 'ghp_rotated');
        $agent->set(CustomFieldEnum::CLAUDE_VAULT_ID->value, 'vlt_01');
        $agent->set(CustomFieldEnum::CLAUDE_VAULT_FINGERPRINT->value, hash('sha256', 'ghp_old'));

        $client = $this->claudeAgentClientReturning($this->currentApp, $this->currentCompany, [
            $this->claudeAgentJsonResponse(200, ['data' => [
                ['id' => 'vcrd_01', 'auth' => ['mcp_server_url' => AgentSpecBuilderService::GITHUB_MCP_URL]],
            ]]),
            $this->claudeAgentJsonResponse(200, ['id' => 'vcrd_01']),
        ]);

        new EnsureGithubVaultAction($agent, $client)->execute();

        $this->assertSame(hash('sha256', 'ghp_rotated'), $agent->get(CustomFieldEnum::CLAUDE_VAULT_FINGERPRINT->value));
    }

    /**
     * The vault row survived but its credential didn't (archived in the console, or a create that
     * died between the two calls). Recreate rather than update a credential that isn't there.
     */
    public function testAVaultMissingItsCredentialGetsANewOne(): void
    {
        $agent = $this->agentWithRepo(token: 'ghp_rotated');
        $agent->set(CustomFieldEnum::CLAUDE_VAULT_ID->value, 'vlt_01');
        $agent->set(CustomFieldEnum::CLAUDE_VAULT_FINGERPRINT->value, hash('sha256', 'ghp_old'));

        $client = $this->claudeAgentClientReturning($this->currentApp, $this->currentCompany, [
            $this->claudeAgentJsonResponse(200, ['data' => []]),
            $this->claudeAgentJsonResponse(200, ['id' => 'vcrd_new']),
        ]);

        new EnsureGithubVaultAction($agent, $client)->execute();

        $this->assertSame(hash('sha256', 'ghp_rotated'), $agent->get(CustomFieldEnum::CLAUDE_VAULT_FINGERPRINT->value));
    }

    public function testAnAgentWithNoTokenGetsNoVault(): void
    {
        $agent = $this->agentWithRepo(token: null);

        $client = $this->claudeAgentClientReturning($this->currentApp, $this->currentCompany, []);

        $this->assertNull(new EnsureGithubVaultAction($agent, $client)->execute());
    }

    public function testAnAgentWithNoRepositoriesGetsNoVault(): void
    {
        $agent = $this->makeClaudeAgent($this->currentApp, $this->currentCompany);
        $agent->set(CustomFieldEnum::CLAUDE_GITHUB_TOKEN->value, 'ghp_test');

        $client = $this->claudeAgentClientReturning($this->currentApp, $this->currentCompany, []);

        $this->assertNull(new EnsureGithubVaultAction($agent, $client)->execute());
    }

    /**
     * Once the vault exists the spec must actually declare the MCP server — otherwise the credential
     * is provisioned and still unreachable.
     */
    public function testTheSpecDeclaresTheGithubMcpServerOnceTheVaultExists(): void
    {
        $agent = $this->agentWithRepo();

        $this->assertSame([], new AgentSpecBuilderService($agent)->build()->mcpServers);

        $client = $this->claudeAgentClientReturning($this->currentApp, $this->currentCompany, [
            $this->claudeAgentJsonResponse(200, ['id' => 'vlt_01']),
            $this->claudeAgentJsonResponse(200, ['id' => 'vcrd_01']),
        ]);
        new EnsureGithubVaultAction($agent, $client)->execute();

        $spec = new AgentSpecBuilderService($agent->refresh())->build();

        $this->assertSame(AgentSpecBuilderService::GITHUB_MCP_URL, $spec->mcpServers[0]['url']);

        // Without always_allow the session parks on a requires_action stop waiting for a
        // confirmation nobody is there to give — the failure this whole path exists to remove.
        $this->assertContains(
            [
                'type' => 'mcp_toolset',
                'mcp_server_name' => AgentSpecBuilderService::GITHUB_MCP_NAME,
                'default_config' => ['permission_policy' => ['type' => 'always_allow']],
            ],
            $spec->tools,
        );
    }
}
