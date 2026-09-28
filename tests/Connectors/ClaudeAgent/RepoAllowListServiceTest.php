<?php

declare(strict_types=1);

namespace Tests\Connectors\ClaudeAgent;

use Baka\Support\Str;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\ClaudeAgent\Actions\OpenSessionAction;
use Kanvas\Connectors\ClaudeAgent\Client;
use Kanvas\Connectors\ClaudeAgent\Enums\CustomFieldEnum;
use Kanvas\Connectors\ClaudeAgent\Services\AgentSpecBuilderService;
use Kanvas\Connectors\ClaudeAgent\Services\CustomToolBridgeService;
use Kanvas\Connectors\ClaudeAgent\Services\RepoAllowListService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Tests\Connectors\Traits\HasClaudeAgentConfiguration;
use Tests\TestCase;

/**
 * The allow-list is the security boundary — the model names a slug and never a URL, so everything
 * here is about what cannot escape it.
 */
final class RepoAllowListServiceTest extends TestCase
{
    use DatabaseTransactions;
    use HasClaudeAgentConfiguration;

    /** Settings live on mysql; agents, types and sessions on intelligence. */
    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    private Apps $currentApp;
    private Companies $currentCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->currentCompany = static::$cachedUser->getCurrentCompany();
    }

    /**
     * @param array<int, array<string, mixed>> $repos
     */
    private function agentWithRepos(array $repos, ?string $token = 'github_pat_test', ?string $vault = null): Agent
    {
        $agent = $this->makeClaudeAgent($this->currentApp, $this->currentCompany);

        $agent->set(CustomFieldEnum::CLAUDE_ALLOWED_REPOS->value, $repos);

        if ($token !== null) {
            $agent->set(CustomFieldEnum::CLAUDE_GITHUB_TOKEN->value, $token);
        }

        if ($vault !== null) {
            $agent->set(CustomFieldEnum::CLAUDE_VAULT_ID->value, $vault);
        }

        return $agent;
    }

    public function testValidateRejectsANonHttpsOrIncompleteUrl(): void
    {
        $this->expectException(ValidationException::class);

        RepoAllowListService::validate([['slug' => 'api', 'url' => 'git@github.com:acme/api.git']]);
    }

    public function testValidateRejectsDuplicateSlugs(): void
    {
        $this->expectException(ValidationException::class);

        RepoAllowListService::validate([
            ['slug' => 'api', 'url' => 'https://github.com/acme/api'],
            ['slug' => 'api', 'url' => 'https://github.com/acme/other'],
        ]);
    }

    public function testValidateKeepsOptionalRulesOfEngagement(): void
    {
        $normalized = RepoAllowListService::validate([[
            'slug' => 'api',
            'url' => 'https://github.com/acme/api',
            'base_branch' => 'development',
            'protected_paths' => ['database/migrations'],
            'unknown_field' => 'dropped',
        ]]);

        $this->assertSame('development', $normalized[0]['base_branch']);
        $this->assertSame(['database/migrations'], $normalized[0]['protected_paths']);
        $this->assertArrayNotHasKey('unknown_field', $normalized[0]);
    }

    /**
     * The core guarantee: a slug outside the list throws before anything is cloned or mounted.
     */
    public function testResolveRefusesASlugOutsideTheAllowList(): void
    {
        $agent = $this->agentWithRepos([['slug' => 'api', 'url' => 'https://github.com/acme/api']]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not in this agent');

        RepoAllowListService::resolve($agent, 'someone-elses-repo');
    }

    public function testSessionResourcesMountEveryAllowedRepo(): void
    {
        $agent = $this->agentWithRepos([
            ['slug' => 'api', 'url' => 'https://github.com/acme/api', 'base_branch' => 'development'],
            ['slug' => 'web', 'url' => 'https://github.com/acme/web'],
        ]);

        $resources = RepoAllowListService::sessionResources($agent);

        $this->assertCount(2, $resources);
        $this->assertSame('github_repository', $resources[0]['type']);
        $this->assertSame('/workspace/api', $resources[0]['mount_path']);
        $this->assertSame(['type' => 'branch', 'name' => 'development'], $resources[0]['checkout']);
        // No base_branch configured means the repo's own default branch.
        $this->assertArrayNotHasKey('checkout', $resources[1]);
    }

    public function testSessionResourcesCanBeNarrowedToASingleSlug(): void
    {
        $agent = $this->agentWithRepos([
            ['slug' => 'api', 'url' => 'https://github.com/acme/api'],
            ['slug' => 'web', 'url' => 'https://github.com/acme/web'],
        ]);

        $resources = RepoAllowListService::sessionResources($agent, ['web']);

        $this->assertCount(1, $resources);
        $this->assertSame('/workspace/web', $resources[0]['mount_path']);
    }

    /**
     * Mounting a repo with no token produces a sandbox where every clone fails. Mounting nothing is
     * the clearer failure.
     */
    public function testNoTokenMeansNoRepositoriesAreMounted(): void
    {
        $agent = $this->agentWithRepos(
            [['slug' => 'api', 'url' => 'https://github.com/acme/api']],
            token: null,
        );

        $this->assertSame([], RepoAllowListService::sessionResources($agent));
        $this->assertNull(RepoAllowListService::promptSection($agent));
    }

    public function testPromptSectionTellsTheAgentWhereEachRepoIsAndWhatIsOffLimits(): void
    {
        $agent = $this->agentWithRepos([[
            'slug' => 'api',
            'url' => 'https://github.com/acme/api',
            'branch_prefix' => 'feat/claude-',
            'protected_paths' => ['database/migrations'],
        ]]);

        $section = (string) RepoAllowListService::promptSection($agent);

        $this->assertStringContainsString('/workspace/api', $section);
        $this->assertStringContainsString('feat/claude-', $section);
        $this->assertStringContainsString('never modify: database/migrations', $section);
    }

    /**
     * A repo mount gives filesystem + git. Opening a PR additionally needs the GitHub MCP server,
     * so it is only declared when the vault that authenticates it is configured too.
     */
    public function testGithubMcpIsDeclaredOnlyWithBothAVaultAndRepos(): void
    {
        $repos = [['slug' => 'api', 'url' => 'https://github.com/acme/api']];
        $bridge = fn (Agent $agent) => new CustomToolBridgeService($agent, []);

        $withoutVault = $this->agentWithRepos($repos);
        $spec = new AgentSpecBuilderService($withoutVault, $bridge($withoutVault))->build();
        $this->assertSame([], $spec->mcpServers);

        $withVault = $this->agentWithRepos($repos, vault: 'vlt_01abc');
        $spec = new AgentSpecBuilderService($withVault, $bridge($withVault))->build();
        $this->assertSame(
            [['type' => 'url', 'name' => 'github', 'url' => AgentSpecBuilderService::GITHUB_MCP_URL]],
            $spec->mcpServers,
        );
        $this->assertContains(
            [
                'type' => 'mcp_toolset',
                'mcp_server_name' => 'github',
                'default_config' => ['permission_policy' => ['type' => 'always_allow']],
            ],
            $spec->tools,
        );
    }

    public function testAVaultWithNoReposDoesNotDeclareMcp(): void
    {
        $agent = $this->agentWithRepos([], vault: 'vlt_01abc');

        $spec = new AgentSpecBuilderService($agent, new CustomToolBridgeService($agent, []))->build();

        $this->assertSame([], $spec->mcpServers);
    }

    /**
     * Session resources attach at creation and can never be added afterwards. Granting a repo to an
     * agent that already has a session must therefore open a NEW one — otherwise the agent keeps
     * landing in an empty /workspace and asking the user for a repo URL it should already have.
     */
    public function testGrantingARepoReopensAnExistingSession(): void
    {
        $agent = $this->makeClaudeAgent($this->currentApp, $this->currentCompany);
        $session = Session::create([
            'apps_id' => $this->currentApp->getId(),
            'companies_id' => $this->currentCompany->getId(),
            'agents_id' => $agent->getId(),
            'uuid' => Str::uuid()->toString(),
            'canal_id' => '',
            'entity_namespace' => '',
            'entity_id' => 0,
            'user' => [],
            'content' => [],
        ]);

        // Two responses: replacing a session archives the one it supersedes before creating the new
        // one. The first call has nothing to archive and simply leaves the extra response unused.
        $open = fn (Agent $a): string => new OpenSessionAction(
            agent: $a,
            session: $session,
            environmentId: 'env_1',
            remoteAgentId: 'agent_1',
            client: $this->claudeAgentClientReturning($this->currentApp, $this->currentCompany, [
                $this->claudeAgentJsonResponse(200, ['id' => 'sesn_new']),
                $this->claudeAgentJsonResponse(200, ['id' => 'sesn_new']),
            ]),
        )->execute();

        $this->assertSame('sesn_new', $open($agent));

        // Same config: reuse, no HTTP at all (empty mock queue would throw).
        $this->assertSame('sesn_reused', (function () use ($session, $agent): string {
            $session->content = [...$session->refresh()->content, 'claude_session_id' => 'sesn_reused'];
            $session->saveQuietly();

            return new OpenSessionAction(
                agent: $agent,
                session: $session,
                environmentId: 'env_1',
                remoteAgentId: 'agent_1',
                client: new Client(
                    $this->currentApp,
                    $this->currentCompany,
                    $this->claudeAgentGuzzleReturning([]),
                ),
            )->execute();
        })());

        // Now grant a repo — the stored session no longer matches the config that shaped it.
        $agent->set(CustomFieldEnum::CLAUDE_ALLOWED_REPOS->value, [
            ['slug' => 'api', 'url' => 'https://github.com/acme/api'],
        ]);
        $agent->set(CustomFieldEnum::CLAUDE_GITHUB_TOKEN->value, 'github_pat_test');

        $this->assertSame('sesn_new', $open($agent->refresh()));
    }

    /**
     * Repos ride the spec, so granting one versions the remote agent — the same rule as tools.
     */
    public function testGrantingARepoChangesTheSpecFingerprint(): void
    {
        $bare = $this->makeClaudeAgent($this->currentApp, $this->currentCompany);
        $before = new AgentSpecBuilderService($bare, new CustomToolBridgeService($bare, []))->build();

        $withRepo = $this->agentWithRepos([['slug' => 'api', 'url' => 'https://github.com/acme/api']]);
        $after = new AgentSpecBuilderService($withRepo, new CustomToolBridgeService($withRepo, []))->build();

        $this->assertNotSame($before->fingerprint(), $after->fingerprint());
    }
}
