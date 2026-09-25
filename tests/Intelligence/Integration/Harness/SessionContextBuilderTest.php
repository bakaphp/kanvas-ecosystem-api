<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Baka\Contracts\CompanyInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenCode\DataTransferObject\CodingRepository;
use Kanvas\Connectors\OpenCode\Services\SessionContextBuilder;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class SessionContextBuilderTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    public function testContextDocumentIsNullWhenThereIsNothingToSay(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);

        $this->assertNull(new SessionContextBuilder($agent)->contextDocument());
    }

    public function testContextDocumentCarriesEveryHandoffTheAgentLeftBehind(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);

        $this->seedCompletedSession($app, $company, $agent, 'The queue worker needs the app rebound');
        $this->seedCompletedSession($app, $company, $agent, 'Migrations run on the intelligence connection');

        $document = new SessionContextBuilder($agent)->contextDocument();

        $this->assertNotNull($document);
        $this->assertStringContainsString('The queue worker needs the app rebound', $document);
        $this->assertStringContainsString('Migrations run on the intelligence connection', $document);
    }

    /**
     * The guard against confidently-wrong advice: what an agent learned in another codebase is usually
     * wrong here, so a handoff from a different repository must not reach this session's context.
     */
    public function testHandoffsFromAnotherRepositoryAreNeverIncluded(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);

        $this->seedCompletedSession($app, $company, $agent, 'Widgets uses pnpm, not npm', 'widgets');
        $this->seedCompletedSession($app, $company, $agent, 'Gadgets has no test suite at all', 'gadgets');

        $document = new SessionContextBuilder($agent, $this->repository())->contextDocument();

        $this->assertNotNull($document);
        $this->assertStringContainsString('Widgets uses pnpm, not npm', $document);
        $this->assertStringNotContainsString('Gadgets has no test suite at all', $document);
    }

    public function testContextDocumentCarriesTheRepositoryRules(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);

        $rules = 'Every migration goes on the intelligence connection.';
        $document = new SessionContextBuilder($agent, $this->repository($rules))->contextDocument();

        $this->assertNotNull($document);
        $this->assertStringContainsString($rules, $document);
        $this->assertStringContainsString('widgets', $document);
    }

    public function testAgentDocumentNamesTheAgentAndStatesTheRulesOfEngagement(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);

        $document = new SessionContextBuilder($agent)->agentDocument();

        $this->assertStringContainsString($agent->name, $document);
        $this->assertStringContainsString('## Rules of engagement', $document);
        $this->assertStringContainsString('You cannot push, commit to a remote, or open a pull request', $document);
        $this->assertStringContainsString('Never print, echo or otherwise reveal credentials', $document);

        // A brief that also asks for a commit, a push or a PR must not stop the worker doing the part
        // that IS its job. Told to do four things and forbidden three, it answered in prose and wrote
        // nothing — twice, at real cost.
        $this->assertStringContainsString('IGNORE those parts and do the file changes', $document);
        $this->assertStringContainsString('EDIT THE FILES', $document);
    }

    /**
     * The orchestrator's persona must never reach the worker.
     *
     * `role` and the type's `soul` say "you delegate coding work… you are accountable for describing it
     * and reporting back what changed". Given to the agent that is supposed to DO the work, it did as
     * it was told: wrote the file's contents into its reply, claimed the file existed, and changed
     * nothing. Two paid jobs produced an empty diff.
     */
    public function testAgentDocumentDoesNotHandTheWorkerTheOrchestratorsPersona(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user, [
            'role' => 'You delegate coding work to a runtime Kanvas operates.',
        ]);

        $document = new SessionContextBuilder($agent)->agentDocument();

        $this->assertStringNotContainsString('You delegate coding work', $document);
        $this->assertStringContainsString('You edit files yourself', $document);
    }

    private function repository(?string $rules = null): CodingRepository
    {
        return new CodingRepository(
            slug: 'widgets',
            cloneUrl: 'https://git.example.test/acme/widgets.git',
            rules: $rules,
        );
    }

    private function seedCompletedSession(
        Apps $app,
        CompanyInterface $company,
        Agent $agent,
        string $handoff,
        ?string $repoSlug = null
    ): AgentTaskSession {
        $session = new AgentTaskSession();
        $session->apps_id = $app->getId();
        $session->companies_id = $company->getId();
        $session->agent_id = $agent->getId();
        // No FK on the table, and the history query never joins the task.
        $session->task_id = 0;
        $session->repo_slug = $repoSlug;
        $session->harness = 'opencode';
        $session->status = 'completed';
        $session->handoff = $handoff;
        $session->completed_at = Carbon::now();
        $session->saveOrFail();

        return $session;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function makeAgent(
        Apps $app,
        CompanyInterface $company,
        Users $user,
        array $attributes = []
    ): Agent {
        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'user_id' => $user->getId(),
                'is_active' => true,
                ...$attributes,
            ]);
    }

    /**
     * @return array{0: Apps, 1: CompanyInterface, 2: Users}
     */
    private function context(): array
    {
        /** @var Users $user */
        $user = auth()->user();

        return [app(Apps::class), $user->getCurrentCompany(), $user];
    }
}
