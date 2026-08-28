<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Services\ProjectContextService;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * What the orchestrator can see about work that already happened.
 *
 * The bundle used to carry `status` and nothing else about a task, and dropped a plan the moment it
 * closed. So an agent finished a job, wrote the S3 URL of the file it produced onto its task, and the
 * PM — asked for that link minutes later — had no plan, no task and no result in context. It did not
 * say "I can't see it": it explained the absence, fluently and wrongly. Reporting status without
 * results is what makes an orchestrator confabulate.
 */
class ProjectContextResultsTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = [null, 'intelligence'];

    public function test_a_finished_task_carries_what_it_produced(): void
    {
        $url = 'https://cdn.example.io/' . uniqid() . '.pdf';
        $project = $this->makeProject();
        $plan = $this->makePlan(['project_id' => $project->getId()]);
        $task = $this->makeTask($plan, TaskStatusEnum::DONE);
        $task->result = ['worker_summary' => 'Generated the certificate. File URL: ' . $url];
        $task->saveQuietly();

        $this->assertStringContainsString($url, $this->bundleJson($project));
    }

    /** A plan closing is when someone starts asking about it, so that is the worst time to lose it. */
    public function test_a_recently_finished_plan_stays_visible(): void
    {
        $project = $this->makeProject();
        $plan = $this->makePlan([
            'project_id' => $project->getId(),
            'status' => PlanStatusEnum::DONE->value,
        ]);

        $planIds = array_column(
            new ProjectContextService()->buildContextBundle($project->refresh())->plans,
            'plan_id',
        );

        $this->assertContains($plan->getId(), $planIds);
    }

    /**
     * The regression that hid the link: a 724-character summary against a 600-character head-only
     * cap. The URL was the last thing written, so truncation kept the preamble and dropped the answer.
     */
    public function test_a_long_result_keeps_its_tail_where_the_payload_is(): void
    {
        $url = 'https://cdn.example.io/' . uniqid() . '.pdf';
        $project = $this->makeProject();
        $plan = $this->makePlan(['project_id' => $project->getId()]);
        $task = $this->makeTask($plan, TaskStatusEnum::DONE);
        $task->result = ['worker_summary' => str_repeat('preamble narration. ', 300) . 'File URL: ' . $url];
        $task->saveQuietly();

        $this->assertStringContainsString($url, $this->bundleJson($project));
    }

    /** A task nobody has run yet has nothing to report, and an empty key is noise in every turn. */
    public function test_a_task_with_no_result_carries_no_result_key(): void
    {
        $project = $this->makeProject();
        $plan = $this->makePlan(['project_id' => $project->getId()]);
        $this->makeTask($plan, TaskStatusEnum::PENDING);

        $bundle = new ProjectContextService()->buildContextBundle($project->refresh());

        foreach ($bundle->plans as $planRow) {
            foreach ($planRow['tasks'] as $taskRow) {
                $this->assertArrayNotHasKey('result', $taskRow);
            }
        }
    }

    private function bundleJson(Project $project): string
    {
        return (string) json_encode(
            new ProjectContextService()->buildContextBundle($project->refresh())->plans,
            JSON_UNESCAPED_SLASHES,
        );
    }

    private function makeProject(): Project
    {
        $token = uniqid();

        return Project::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => static::$cachedUser->getCurrentCompany()->getId(),
            'users_id' => static::$cachedUser->getId(),
            'agent_id' => $this->makeAgent()->getId(),
            'title' => 'Context test ' . $token,
            'slug' => 'context-test-' . $token,
            'status' => 'active',
            'priority' => 0,
            'completion_pct' => 0,
            'is_deleted' => 0,
        ]);
    }
}
