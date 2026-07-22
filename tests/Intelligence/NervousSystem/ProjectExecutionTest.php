<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Users\Models\Users;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\TestCase;

class ProjectExecutionTest extends TestCase
{
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

    private function makeProject(Apps $app, Companies $company, Users $user, Agent $agent): Project
    {
        return new CreateProjectAction(
            ProjectData::from(
                $app,
                $user,
                $company,
                ['title' => 'Execution test', 'agent_id' => $agent->id],
            ),
        )->execute();
    }

    private function planFor(Project $project, Apps $app, Companies $company, Users $user, int $completion): void
    {
        $plan = new Plan();
        $plan->apps_id = $app->getId();
        $plan->companies_id = $company->getId();
        $plan->users_id = $user->getId();
        $plan->project_id = $project->id;
        $plan->plan_type = 'project_work';
        $plan->title = 'Plan ' . $completion;
        $plan->status = 'active';
        $plan->completion_pct = $completion;
        $plan->saveOrFail();
    }

    public function testRecomputeCompletionPctRollsUpFromPlans(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        $project = $this->makeProject($app, $company, $user, $agent);

        $this->assertSame(0, $project->recomputeCompletionPct());

        $this->planFor($project, $app, $company, $user, 100);
        $this->planFor($project, $app, $company, $user, 20);

        $this->assertSame(60, $project->recomputeCompletionPct());
        $this->assertSame(60, (int) $project->refresh()->completion_pct);
    }

    public function testWakeAgentRunsPmAndReplies(): void
    {
        [$app, $company, $user] = $this->context();

        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create([
                'provider' => 'neuron',
                'handler' => SalesNeuronAgentStub::class,
            ]);

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'agent_type_id' => $agentType->getId(),
                'user_id' => $user->getId(),
                'is_active' => true,
            ]);

        $project = $this->makeProject($app, $company, $user, $agent);

        new WakeAgentForProjectJob(
            $project,
            WakeAgentForProjectJob::REASON_INGEST,
            'Client wants dark mode by Friday.',
        )->handle();

        $this->assertDatabaseHas(
            'nervous_system_events',
            [
                'source_entity_type' => Project::class,
                'source_entity_id' => $project->id,
                'event_type' => 'project.agent.invoked',
            ],
            'intelligence',
        );
        $this->assertDatabaseHas(
            'nervous_system_events',
            [
                'source_entity_type' => Project::class,
                'source_entity_id' => $project->id,
                'event_type' => 'project.agent.replied',
            ],
            'intelligence',
        );
    }
}
