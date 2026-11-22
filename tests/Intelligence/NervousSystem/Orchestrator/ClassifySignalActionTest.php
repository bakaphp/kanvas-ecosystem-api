<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem\Orchestrator;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Orchestrator\Routing\Actions\ClassifySignalAction;
use Kanvas\NervousSystem\Orchestrator\Routing\DataTransferObject\RoutingCandidate;
use Kanvas\NervousSystem\Orchestrator\Signals\DataTransferObject\InboundSignal;
use Kanvas\NervousSystem\Orchestrator\Signals\Enums\SignalSourceEnum;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Enums\ProjectIngestTypeEnum;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Users\Models\Users;
use Laravel\Ai\StructuredAnonymousAgent;
use Tests\Intelligence\NervousSystem\Orchestrator\Concerns\MakesMeetingRoutingFixtures;
use Tests\TestCase;

class ClassifySignalActionTest extends TestCase
{
    use DatabaseTransactions;
    use MakesMeetingRoutingFixtures;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social', 'workflow'];

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

    private function makeProject(
        Apps $app,
        Companies $company,
        Users $owner,
        string $title,
    ): Project {
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $owner->getId(), 'is_active' => true]);

        return new CreateProjectAction(
            ProjectData::from(
                $app,
                $owner,
                $company,
                [
                    'title' => $title,
                    'objective' => "Deliver {$title}",
                    'agent_id' => $agent->id,
                ],
            ),
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
        $acme = $this->makeProject(
            $app,
            $company,
            $user,
            'Acme',
        );
        $globex = $this->makeProject(
            $app,
            $company,
            $user,
            'Globex',
        );

        StructuredAnonymousAgent::fake([[
            'candidate' => 'project:' . $acme->id,
            'confidence' => 0.86,
            'reason' => 'Transcript is about the Acme rollout.',
        ]]);

        $result = new ClassifySignalAction($this->signal())->execute([
            RoutingCandidate::forProject($acme),
            RoutingCandidate::forProject($globex),
        ]);

        $this->assertSame((int) $acme->id, $result->candidate?->project()?->getId());
        $this->assertSame(0.86, $result->confidence);
    }

    public function testClassifierCanPickALeadOverAProject(): void
    {
        [$app, $company, $user] = $this->context();
        $acme = $this->makeProject(
            $app,
            $company,
            $user,
            'Acme',
        );
        $lead = $this->lead($company, $this->person($company, $this->uniqueEmail()));

        StructuredAnonymousAgent::fake([[
            'candidate' => 'lead:' . $lead->getId(),
            'confidence' => 0.8,
            'reason' => 'A pricing call, not project work.',
        ]]);

        $result = new ClassifySignalAction($this->signal())->execute([
            RoutingCandidate::forLead($lead),
            RoutingCandidate::forProject($acme),
        ]);

        $this->assertSame('lead:' . $lead->getId(), $result->candidate?->key());
    }

    public function testNoneBecomesNoAction(): void
    {
        [$app, $company, $user] = $this->context();
        $acme = $this->makeProject(
            $app,
            $company,
            $user,
            'Acme',
        );

        StructuredAnonymousAgent::fake([[
            'candidate' => ClassifySignalAction::NONE,
            'confidence' => 0.0,
            'reason' => 'Internal FYI, no project.',
        ]]);

        $result = new ClassifySignalAction($this->signal())->execute([RoutingCandidate::forProject($acme)]);

        $this->assertNull($result->candidate);
    }

    public function testHallucinatedKeyOutsideCandidatesBecomesNoAction(): void
    {
        [$app, $company, $user] = $this->context();
        $acme = $this->makeProject(
            $app,
            $company,
            $user,
            'Acme',
        );

        StructuredAnonymousAgent::fake([[
            'candidate' => 'project:999999999',
            'confidence' => 0.9,
            'reason' => 'Model invented an id.',
        ]]);

        $result = new ClassifySignalAction($this->signal())->execute([RoutingCandidate::forProject($acme)]);

        $this->assertNull($result->candidate);
    }

    public function testNoCandidatesShortCircuitsWithoutCallingTheModel(): void
    {
        $result = new ClassifySignalAction($this->signal())->execute([]);

        $this->assertNull($result->candidate);
        $this->assertSame(0.0, $result->confidence);
    }
}
