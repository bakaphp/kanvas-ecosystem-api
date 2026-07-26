<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem\Orchestrator;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Orchestrator\Routing\Actions\MatchSignalToProjectsAction;
use Kanvas\NervousSystem\Orchestrator\Signals\DataTransferObject\InboundSignal;
use Kanvas\NervousSystem\Orchestrator\Signals\Enums\SignalSourceEnum;
use Kanvas\NervousSystem\Project\Actions\AddProjectMemberAction;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Enums\ProjectIngestTypeEnum;
use Kanvas\NervousSystem\Project\Enums\ProjectMemberRoleEnum;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class MatchSignalToProjectsActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'social', 'workflow'];

    /**
     * @return array{0: Apps, 1: Companies, 2: Users}
     */
    private function context(): array
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        return [$app, $user->getCurrentCompany(), $user];
    }

    private function makeProject(Apps $app, Companies $company, Users $owner, string $title): Project
    {
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $owner->getId(), 'is_active' => true]);

        return new CreateProjectAction(
            ProjectData::from($app, $owner, $company, ['title' => $title, 'agent_id' => $agent->id]),
        )->execute();
    }

    private function addMemberWithEmail(Project $project, string $email): Users
    {
        $member = Users::factory()->create(['email' => $email]);
        new AddProjectMemberAction(
            project: $project,
            role: ProjectMemberRoleEnum::CONTRIBUTOR,
            user: $member,
        )->execute();

        return $member;
    }

    /**
     * @param list<string> $actorEmails
     */
    private function signalWithActors(array $actorEmails): InboundSignal
    {
        return new InboundSignal(
            source: SignalSourceEnum::READ_AI,
            kind: ProjectIngestTypeEnum::TRANSCRIPT,
            externalId: 'sess_x',
            title: 'Sync',
            content: 'notes',
            occurredAt: null,
            actors: array_map(fn (string $email): array => ['name' => '', 'email' => $email], $actorEmails),
        );
    }

    public function testMatchesOnlyProjectsWhoseMemberAttended(): void
    {
        [$app, $company, $user] = $this->context();

        $acme = $this->makeProject($app, $company, $user, 'Acme');
        $this->addMemberWithEmail($acme, 'greg@acme.io');

        $globex = $this->makeProject($app, $company, $user, 'Globex');
        $this->addMemberWithEmail($globex, 'sam@globex.io');

        $signal = $this->signalWithActors(['GREG@acme.io', 'someone@ourco.com']);

        $matched = new MatchSignalToProjectsAction($signal)->execute([$acme, $globex]);

        $this->assertCount(1, $matched);
        $this->assertSame((int) $acme->id, (int) $matched[0]->id);
    }

    public function testReturnsEveryProjectWhenMembersOverlapMultiple(): void
    {
        [$app, $company, $user] = $this->context();

        $shared = Users::factory()->create(['email' => 'exec@corp.io']);

        $a = $this->makeProject($app, $company, $user, 'Alpha');
        new AddProjectMemberAction(project: $a, role: ProjectMemberRoleEnum::CONTRIBUTOR, user: $shared)->execute();

        $b = $this->makeProject($app, $company, $user, 'Beta');
        new AddProjectMemberAction(project: $b, role: ProjectMemberRoleEnum::CONTRIBUTOR, user: $shared)->execute();

        $signal = $this->signalWithActors(['exec@corp.io']);

        $matched = new MatchSignalToProjectsAction($signal)->execute([$a, $b]);

        // Ambiguous (member on both) → both returned for the LLM step to disambiguate.
        $ids = array_map(fn (Project $p): int => (int) $p->id, $matched);
        $this->assertContains((int) $a->id, $ids);
        $this->assertContains((int) $b->id, $ids);
    }

    public function testNoMatchWhenNoAttendeeIsAMember(): void
    {
        [$app, $company, $user] = $this->context();

        $project = $this->makeProject($app, $company, $user, 'Gamma');
        $this->addMemberWithEmail($project, 'insider@gamma.io');

        $signal = $this->signalWithActors(['stranger@nowhere.io']);

        $this->assertSame([], new MatchSignalToProjectsAction($signal)->execute([$project]));
    }

    public function testEmptyWhenSignalHasNoActorEmails(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user, 'Delta');

        $signal = $this->signalWithActors([]);

        $this->assertSame([], new MatchSignalToProjectsAction($signal)->execute([$project]));
    }
}
