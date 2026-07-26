<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem\Orchestrator;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Orchestrator\Routing\Actions\RouteSignalAction;
use Kanvas\NervousSystem\Orchestrator\Routing\Enums\RoutingOutcomeEnum;
use Kanvas\NervousSystem\Orchestrator\Signals\DataTransferObject\InboundSignal;
use Kanvas\NervousSystem\Orchestrator\Signals\Enums\SignalSourceEnum;
use Kanvas\NervousSystem\Project\Actions\AddProjectMemberAction;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Enums\ProjectIngestTypeEnum;
use Kanvas\NervousSystem\Project\Enums\ProjectMemberRoleEnum;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Users\Models\Users;
use Laravel\Ai\StructuredAnonymousAgent;
use Tests\TestCase;

class RouteSignalActionTest extends TestCase
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
            ProjectData::from($app, $owner, $company, [
                'title' => $title,
                'objective' => "Deliver {$title}",
                'agent_id' => $agent->id,
            ]),
        )->execute();
    }

    /**
     * @param list<string> $actorEmails
     */
    private function signal(array $actorEmails = []): InboundSignal
    {
        return new InboundSignal(
            source: SignalSourceEnum::READ_AI,
            kind: ProjectIngestTypeEnum::TRANSCRIPT,
            externalId: 'sess_x',
            title: 'Sync',
            content: 'notes',
            occurredAt: null,
            actors: array_map(fn (string $e): array => ['name' => '', 'email' => $e], $actorEmails),
        );
    }

    public function testSoleDeterministicMatchForwardsWithoutLlm(): void
    {
        [$app, $company, $user] = $this->context();
        $acme = $this->makeProject($app, $company, $user, 'Acme');
        $member = Users::factory()->create(['email' => 'greg@acme.io']);
        new AddProjectMemberAction(project: $acme, role: ProjectMemberRoleEnum::CONTRIBUTOR, user: $member)->execute();

        // No LLM fake set — a sole member match must not call the classifier.
        $decision = new RouteSignalAction($this->signal(['greg@acme.io']))->execute([$acme]);

        $this->assertSame(RoutingOutcomeEnum::FORWARD, $decision->outcome);
        $this->assertSame((int) $acme->id, (int) $decision->project?->id);
    }

    public function testHighConfidenceClassificationForwards(): void
    {
        [$app, $company, $user] = $this->context();
        $acme = $this->makeProject($app, $company, $user, 'Acme');
        $globex = $this->makeProject($app, $company, $user, 'Globex');

        StructuredAnonymousAgent::fake([[
            'project_id' => (int) $globex->id,
            'confidence' => 0.9,
            'reason' => 'clearly Globex',
        ]]);

        $decision = new RouteSignalAction($this->signal())->execute([$acme, $globex]);

        $this->assertSame(RoutingOutcomeEnum::FORWARD, $decision->outcome);
        $this->assertSame((int) $globex->id, (int) $decision->project?->id);
    }

    public function testMidConfidenceRaisesApprovalWithSuggestedProject(): void
    {
        [$app, $company, $user] = $this->context();
        $acme = $this->makeProject($app, $company, $user, 'Acme');

        StructuredAnonymousAgent::fake([[
            'project_id' => (int) $acme->id,
            'confidence' => 0.55,
            'reason' => 'probably Acme',
        ]]);

        $decision = new RouteSignalAction($this->signal())->execute([$acme]);

        $this->assertSame(RoutingOutcomeEnum::APPROVAL, $decision->outcome);
        $this->assertSame((int) $acme->id, (int) $decision->project?->id);
    }

    public function testLowConfidenceGoesToTriageWithNoSuggestion(): void
    {
        [$app, $company, $user] = $this->context();
        $acme = $this->makeProject($app, $company, $user, 'Acme');

        StructuredAnonymousAgent::fake([[
            'project_id' => (int) $acme->id,
            'confidence' => 0.2,
            'reason' => 'weak guess',
        ]]);

        $decision = new RouteSignalAction($this->signal())->execute([$acme]);

        $this->assertSame(RoutingOutcomeEnum::TRIAGE, $decision->outcome);
        $this->assertNull($decision->project);
    }

    public function testClassifierNoneDrops(): void
    {
        [$app, $company, $user] = $this->context();
        $acme = $this->makeProject($app, $company, $user, 'Acme');

        StructuredAnonymousAgent::fake([[
            'project_id' => 0,
            'confidence' => 0.0,
            'reason' => 'internal FYI',
        ]]);

        $decision = new RouteSignalAction($this->signal())->execute([$acme]);

        $this->assertSame(RoutingOutcomeEnum::DROP, $decision->outcome);
    }

    public function testNoCandidatesDrops(): void
    {
        $decision = new RouteSignalAction($this->signal())->execute([]);

        $this->assertSame(RoutingOutcomeEnum::DROP, $decision->outcome);
    }
}
