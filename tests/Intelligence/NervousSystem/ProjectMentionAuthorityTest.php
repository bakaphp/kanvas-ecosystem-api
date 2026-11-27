<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\Actions\PostProjectMessageAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\ToolInterface;
use ReflectionMethod;
use Tests\Stubs\Intelligence\TurnFactsProjectManagerAgentStub;
use Tests\TestCase;
use Tests\Traits\MakesEmployees;

/**
 * A project @mention runs with the rights of whoever typed it, and not at all when nobody can be
 * shown to have.
 */
final class ProjectMentionAuthorityTest extends TestCase
{
    use DatabaseTransactions;
    use MakesEmployees;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'social', 'workflow'];

    private Apps $tenantApp;
    private Companies $company;
    private Users $owner;
    private Users $employee;
    private Agent $pm;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantApp = app(Apps::class);
        /** @var Users $owner */
        $owner = auth()->user();
        $this->owner = $owner;
        $this->company = $owner->getCurrentCompany();
        $this->employee = $this->makeEmployee();

        $type = AgentType::factory()->withAppId($this->tenantApp->getId())->create([
            'provider' => 'neuron',
            'handler' => TurnFactsProjectManagerAgentStub::class,
        ]);
        $this->pm = Agent::factory()
            ->withAppId($this->tenantApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['agent_type_id' => $type->getId()]);

        $this->project = new CreateProjectAction(
            ProjectData::from(
                $this->tenantApp,
                $this->owner,
                $this->company,
                [
                    'title' => 'Mention authority',
                    'objective' => 'Ship it',
                    'agent_id' => $this->pm->getId(),
                ],
            ),
        )->execute()->refresh();

        TurnFactsProjectManagerAgentStub::$lastUnattended = null;
        TurnFactsProjectManagerAgentStub::$lastRequestingHuman = null;
        TurnFactsProjectManagerAgentStub::$lastTools = [];
    }

    public function testAMentionIsAuthorisedAsItsAuthorNotTheProjectOwner(): void
    {
        $this->assertTrue($this->owner->isAdmin());
        $this->assertFalse($this->employee->isAdmin());
        $mention = $this->mentionBy($this->employee);

        [$user, $human, $unattended] = $this->identity($this->mentionJob($mention));

        $this->assertTrue($this->owner->is($user));
        $this->assertTrue($this->employee->is($human));
        $this->assertFalse($unattended);
    }

    public function testADeletedMentionNeverRunsTheAgent(): void
    {
        $mention = $this->mentionBy($this->employee);
        $mention->is_deleted = 1;
        $mention->saveQuietly();
        $job = $this->mentionJob($mention);

        $this->assertNull($this->identity($job));

        $job->handle();

        $this->assertNull(TurnFactsProjectManagerAgentStub::$lastUnattended);
        $this->assertSame([], TurnFactsProjectManagerAgentStub::$lastTools);
    }

    /**
     * Running it as the agent's own user is not a safe fallback: an agent can sit on an administrator's
     * login, or hold the hire ability itself.
     */
    public function testAMissingAuthorCannotBorrowEvenAnAdminAgentsRights(): void
    {
        $this->pm->user_id = $this->owner->getId();
        $this->pm->saveOrFail();
        $this->assertTrue($this->pm->refresh()->user->isAdmin());
        $mention = $this->mentionBy($this->employee);
        $this->employee->is_deleted = 1;
        $this->employee->saveQuietly();

        $this->mentionJob($mention)->handle();

        $this->assertNull(TurnFactsProjectManagerAgentStub::$lastRequestingHuman);
        $this->assertSame([], TurnFactsProjectManagerAgentStub::$lastTools);
    }

    public function testADeletedReplyNeverFallsBackToTheAdminWhoStartedTheThread(): void
    {
        $root = $this->mentionBy($this->owner);
        $mention = $this->mentionBy($this->employee);
        $mention->is_deleted = 1;
        $mention->saveQuietly();
        $job = $this->mentionJob($mention, threadRoot: $root);

        $this->assertNull($this->identity($job));

        $job->handle();

        $this->assertSame([], TurnFactsProjectManagerAgentStub::$lastTools);
    }

    public function testTheAuthorIsTheMentionNotTheThreadItSitsIn(): void
    {
        $root = $this->mentionBy($this->owner);
        $mention = $this->mentionBy($this->employee);

        [, $human] = $this->identity($this->mentionJob($mention, threadRoot: $root));

        $this->assertTrue($this->employee->is($human));
    }

    public function testAMentionWrittenByTheAgentItselfHasNoPersonBehindIt(): void
    {
        $mention = $this->mentionBy($this->pm->user);

        $this->assertNull($this->identity($this->mentionJob($mention)));
    }

    public function testAMentionFromAnotherCompanyHasNoAuthorHere(): void
    {
        $mention = $this->mentionBy($this->employee);
        $mention->companies_id = $this->company->getId() + 999999;
        $mention->saveQuietly();

        $this->assertNull($this->identity($this->mentionJob($mention)));
    }

    public function testAHeartbeatIsUnattendedAndRunsAsTheOwnerAsBefore(): void
    {
        $job = new WakeAgentForProjectJob($this->project, WakeAgentForProjectJob::REASON_HEARTBEAT);

        [$user, $human, $unattended] = $this->identity($job);

        $this->assertTrue($this->owner->is($user));
        $this->assertNull($human);
        $this->assertTrue($unattended);
    }

    public function testTheWakeHandsTheAuthorToTheAgent(): void
    {
        $mention = $this->mentionBy($this->employee);

        $this->mentionJob($mention)->handle();

        $this->assertFalse(TurnFactsProjectManagerAgentStub::$lastUnattended);
        $this->assertTrue($this->employee->is(TurnFactsProjectManagerAgentStub::$lastRequestingHuman));
    }

    public function testANonAdminAuthorCannotHireEither(): void
    {
        $this->mentionJob($this->mentionBy($this->employee))->handle();

        $hired = $this->lastTool('hire_agent')(name: 'Extra', role: 'Helper', instructions: 'Help out.');

        $this->assertFalse($hired['hired'] ?? false);
    }

    public function testRemindMeOnAProjectMentionLandsOnWhoeverTypedIt(): void
    {
        $this->mentionJob($this->mentionBy($this->employee))->handle();

        $scheduled = $this->lastTool('schedule_reminder')(
            message: 'Check the invoice',
            run_at: now()->addDay()->toIso8601String(),
        );

        $this->assertArrayNotHasKey('error', $scheduled);
        $this->assertSame(1, ScheduledAction::query()->where('users_id', $this->employee->getId())->count());
        $this->assertSame(
            0,
            ScheduledAction::query()
                ->where('users_id', $this->owner->getId())
                ->where('created_at', '>=', now()->subMinute())
                ->count(),
        );

        $listed = $this->lastTool('list_scheduled_actions')();

        $this->assertStringContainsString('Check the invoice', (string) json_encode($listed));
    }

    private function mentionBy(Users $author): Message
    {
        return new PostProjectMessageAction(
            project: $this->project,
            verb: 'note',
            content: '@pm hire someone for this',
            author: $author,
        )->execute();
    }

    private function mentionJob(Message $mention, ?Message $threadRoot = null): WakeAgentForProjectJob
    {
        return new WakeAgentForProjectJob(
            $this->project,
            WakeAgentForProjectJob::REASON_MENTION,
            'hire someone for this',
            (int) ($threadRoot ?? $mention)->getId(),
            mentionMessageId: (int) $mention->getId(),
        );
    }

    /**
     * @return array{0: Users, 1: ?Users, 2: bool}|null
     */
    private function identity(WakeAgentForProjectJob $job): ?array
    {
        return new ReflectionMethod(WakeAgentForProjectJob::class, 'turnIdentity')
            ->invoke($job, $this->pm, $this->owner);
    }

    private function lastTool(string $name): ToolInterface
    {
        foreach (TurnFactsProjectManagerAgentStub::$lastTools as $tool) {
            if ($tool instanceof ToolInterface && $tool->getName() === $name) {
                return $tool;
            }
        }

        $this->fail("The agent was not given {$name}.");
    }
}
