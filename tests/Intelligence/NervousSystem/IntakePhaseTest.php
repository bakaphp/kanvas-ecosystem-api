<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Neuron\Tools\Capability\ReportCapabilityGapTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\AssignNervousSystemPlanTool;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\NervousSystem\Plan\Actions\ChaseStaleIntakeAction;
use Kanvas\NervousSystem\Plan\Actions\RecordCapabilityGapAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Brief;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\WorkClassEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

class IntakePhaseTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = [null, 'intelligence'];

    public function test_a_brief_missing_required_fields_is_not_dispatchable(): void
    {
        $brief = Brief::of(WorkClassEnum::CODE, ['objective' => 'Add an Odoo connector']);

        $this->assertFalse($brief->isDispatchable());
        $this->assertSame(['repository', 'acceptance_criteria'], $brief->missingFields());
    }

    /** The checklist is the interview's stopping condition, so it has to produce the questions. */
    public function test_a_brief_turns_its_gaps_into_questions_to_ask(): void
    {
        $questions = Brief::of(WorkClassEnum::OUTBOUND, ['objective' => 'Chase overdue invoices'])
            ->outstandingQuestions();

        $this->assertCount(2, $questions);
        $this->assertStringContainsString('Who receives this', $questions[0]);
        $this->assertStringContainsString('signs off', $questions[1]);
    }

    public function test_a_complete_brief_is_dispatchable(): void
    {
        $brief = Brief::of(WorkClassEnum::RESEARCH, ['objective' => 'Market sizing'])
            ->withFields(['questions' => ['How big is the DR dealer market?']]);

        $this->assertTrue($brief->isDispatchable());
        $this->assertSame([], $brief->missingFields());
    }

    /** Your rule, enforced at the entry point rather than asked for in a prompt. */
    public function test_an_intake_plan_cannot_be_assigned(): void
    {
        $plan = $this->makePlan(['status' => PlanStatusEnum::INTAKE->value]);
        $agent = $this->makeAgent();

        $result = new AssignNervousSystemPlanTool()
            ->withContext(app(Apps::class), static::$cachedUser->getCurrentCompany(), static::$cachedUser)
            ->__invoke(plan_id: $plan->getId(), agent_id: $agent->getId());

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('still in intake', $result['error']);
        // Not "has no agent" — a plan is created with one. The guard's job is that the refused
        // assignment did not move it to the agent we asked for.
        $this->assertNotSame($agent->getId(), $plan->refresh()->agent_id);
    }

    public function test_an_unapproved_plan_cannot_be_assigned_either(): void
    {
        $plan = $this->makePlan(['status' => PlanStatusEnum::AWAITING_APPROVAL->value]);
        $agent = $this->makeAgent();

        $result = new AssignNervousSystemPlanTool()
            ->withContext(app(Apps::class), static::$cachedUser->getCurrentCompany(), static::$cachedUser)
            ->__invoke(plan_id: $plan->getId(), agent_id: $agent->getId());

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('awaiting human approval', $result['error']);
    }

    public function test_a_fresh_intake_is_not_chased_yet(): void
    {
        $plan = $this->makePlan(['status' => PlanStatusEnum::INTAKE->value]);

        $this->assertSame(
            ChaseStaleIntakeAction::RESULT_NOT_STALE,
            new ChaseStaleIntakeAction($plan)->execute(),
        );
    }

    public function test_an_unanswered_intake_is_chased_once_per_window(): void
    {
        $plan = $this->makePlan(['status' => PlanStatusEnum::INTAKE->value]);

        $this->assertSame(
            ChaseStaleIntakeAction::RESULT_CHASED,
            new ChaseStaleIntakeAction($plan, staleAfterHours: 24, force: true)->execute(),
        );

        // Second sweep in the same window: the plan is stale again but was just chased.
        $plan->refresh();
        $plan->created_at = Carbon::now()->subDays(5);
        $plan->saveQuietly();

        $this->assertSame(
            ChaseStaleIntakeAction::RESULT_RECENTLY_CHASED,
            new ChaseStaleIntakeAction($plan->refresh(), staleAfterHours: 24)->execute(),
        );
    }

    /** "Chase or drop" — this is the drop. */
    public function test_an_intake_nobody_answers_is_cancelled_with_a_reason(): void
    {
        $plan = $this->makePlan([
            'status' => PlanStatusEnum::INTAKE->value,
            'input' => ['intake_chases' => ChaseStaleIntakeAction::MAX_CHASES],
        ]);

        $result = new ChaseStaleIntakeAction($plan, staleAfterHours: 24, force: true)->execute();

        $this->assertSame(ChaseStaleIntakeAction::RESULT_CANCELLED, $result);

        $plan->refresh();
        $this->assertSame(PlanStatusEnum::CANCELLED->value, $plan->status);
        $this->assertStringContainsString('Intake abandoned', (string) $plan->error_message);
    }

    /** A repeated ask is a stronger signal, so it counts rather than duplicating. */
    public function test_the_same_capability_gap_is_counted_not_duplicated(): void
    {
        $agent = $this->makeAgent();

        $first = new RecordCapabilityGapAction($agent, 'create a new Google Sheet')->execute();
        $second = new RecordCapabilityGapAction($agent, 'Create A New Google Sheet')->execute();

        $this->assertSame($first->getId(), $second->getId());
        $this->assertSame(2, $second->input['request_count']);
    }

    /**
     * Regression: a PM filed a gap for "recurring nurturing emails" while holding
     * `schedule_agent_task`. The tool now searches first and refuses until the caller argues past
     * what it found.
     */
    public function test_a_gap_is_refused_when_related_tools_exist_and_no_reason_is_given(): void
    {
        $agent = $this->makeAgent();
        $this->catalogTool('zzqq_schedule_task', 'Schedule zzqq work, repeating via recurrence_cron.');

        $result = new ReportCapabilityGapTool($agent)->__invoke(topic: 'schedule zzqq work');

        $this->assertSame('error', $result['status']);
        $this->assertContains('zzqq_schedule_task', $result['related_tools']);
        $this->assertStringContainsString('Not filed', $result['message']);
    }

    /** A near-match is not the same capability, so a reasoned gap still files. */
    public function test_a_gap_files_when_the_caller_says_why_the_near_match_does_not_fit(): void
    {
        $agent = $this->makeAgent();
        $this->catalogTool('zzqq_schedule_task', 'Schedule zzqq work, repeating via recurrence_cron.');

        $result = new ReportCapabilityGapTool($agent)->__invoke(
            topic: 'schedule zzqq work',
            context: 'Needed for the pilot.',
            why_existing_tools_do_not_fit: 'zzqq_schedule_task only schedules, it cannot author the content.',
        );

        $this->assertSame('success', $result['status']);

        $plan = Plan::find($result['plan_id']);
        $this->assertContains('zzqq_schedule_task', $plan->input['considered_tools']);
        $this->assertStringContainsString('cannot author', (string) $plan->input['why_not_fit']);
    }

    /**
     * Nothing matched, so there is nothing to argue against — file it directly.
     *
     * The topic is deliberate nonsense. Stemming made the catalog match generously, so an ordinary
     * English phrase almost always hits something: an earlier version of this test used the word
     * "capability" and was refused by the tools named for it. That looseness is the point elsewhere —
     * it is what stops a quiet concept being missed — but it does mean a truly unmatched topic is now
     * rare, and the guard asks for justification most of the time.
     */
    public function test_a_gap_files_without_argument_when_nothing_matches(): void
    {
        $result = new ReportCapabilityGapTool($this->makeAgent())
            ->__invoke(topic: 'zzqqxx plorbulate grimwad');

        $this->assertSame('success', $result['status']);
    }

    private function catalogTool(string $name, string $description): Tool
    {
        return Tool::create([
            'apps_id' => app(Apps::class)->getId(),
            'name' => $name,
            'description' => $description,
            'tool_type' => 'system',
            'frameworks' => ['neuron'],
            'version' => '1.0.0',
            'is_active' => 1,
            'is_deleted' => 0,
        ]);
    }
}
