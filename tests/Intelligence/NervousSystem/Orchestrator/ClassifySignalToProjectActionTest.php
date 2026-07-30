<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem\Orchestrator;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Orchestrator\Routing\Actions\ClassifySignalToProjectAction;
use Kanvas\NervousSystem\Orchestrator\Signals\DataTransferObject\InboundSignal;
use Kanvas\NervousSystem\Orchestrator\Signals\Enums\SignalSourceEnum;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Enums\ProjectIngestTypeEnum;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Users\Models\Users;
use Laravel\Ai\StructuredAnonymousAgent;
use Tests\TestCase;

class ClassifySignalToProjectActionTest extends TestCase
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

    private function signal(): InboundSignal
    {
        return new InboundSignal(
            source: SignalSourceEnum::READ_AI,
            kind: ProjectIngestTypeEnum::TRANSCRIPT,
            externalId: 'sess_x',
            title: 'Acme onboarding',
            content: 'We discussed the Acme rollout timeline.',
            occurredAt: null,
            actors: [],
        );
    }

    public function testClassifierPicksTheModelsProject(): void
    {
        [$app, $company, $user] = $this->context();
        $acme = $this->makeProject($app, $company, $user, 'Acme');
        $globex = $this->makeProject($app, $company, $user, 'Globex');

        StructuredAnonymousAgent::fake([[
            'project_id' => (int) $acme->id,
            'confidence' => 0.86,
            'reason' => 'Transcript is about the Acme rollout.',
        ]]);

        $result = new ClassifySignalToProjectAction($this->signal())->execute([$acme, $globex]);

        $this->assertSame((int) $acme->id, $result->projectId);
        $this->assertSame(0.86, $result->confidence);
    }

    public function testProjectIdZeroBecomesNoAction(): void
    {
        [$app, $company, $user] = $this->context();
        $acme = $this->makeProject($app, $company, $user, 'Acme');

        StructuredAnonymousAgent::fake([[
            'project_id' => 0,
            'confidence' => 0.0,
            'reason' => 'Internal FYI, no project.',
        ]]);

        $result = new ClassifySignalToProjectAction($this->signal())->execute([$acme]);

        $this->assertNull($result->projectId);
    }

    public function testHallucinatedIdOutsideCandidatesBecomesNoAction(): void
    {
        [$app, $company, $user] = $this->context();
        $acme = $this->makeProject($app, $company, $user, 'Acme');

        StructuredAnonymousAgent::fake([[
            'project_id' => 999999999,
            'confidence' => 0.9,
            'reason' => 'Model invented an id.',
        ]]);

        $result = new ClassifySignalToProjectAction($this->signal())->execute([$acme]);

        $this->assertNull($result->projectId);
    }

    public function testNoCandidatesShortCircuitsWithoutCallingTheModel(): void
    {
        $result = new ClassifySignalToProjectAction($this->signal())->execute([]);

        $this->assertNull($result->projectId);
        $this->assertSame(0.0, $result->confidence);
    }
}
