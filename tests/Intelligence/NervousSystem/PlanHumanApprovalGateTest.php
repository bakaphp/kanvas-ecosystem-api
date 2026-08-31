<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\ApproveNervousSystemPlanTool;
use Kanvas\NervousSystem\Plan\Actions\ApprovePlanAction;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\Actions\UpdatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Users\Models\Users;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * "Wait for a human" has to be a gate, not a sentence.
 *
 * On plan 25667 the PM asked @kaioken to approve a $500 spend, and twenty-five seconds later posted
 * the approval as itself and carried on. Nothing was actually holding the work: `requires_human_approval`
 * was false, `approved_at` null, and the "approval" existed only as prose in a comment. The mention it
 * sent reached nobody either — user 2 backs 28 agents, so the notifier drops it as a bot self-mention —
 * so from the PM's side the question simply looked unanswered.
 */
final class PlanHumanApprovalGateTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = ['mysql', 'intelligence', 'social'];

    public function testRequestingApprovalHoldsThePlanBeforeAnyWorkStarts(): void
    {
        $plan = $this->plan(requiresApproval: true);

        $this->assertSame(PlanStatusEnum::AWAITING_APPROVAL->value, $plan->status);
        $this->assertTrue((bool) $plan->requires_human_approval);
        $this->assertNull($plan->approved_at);
    }

    /** Without the flag nothing is held — that is the default and must stay that way. */
    public function testAPlanThatDoesNotAskForApprovalRunsAsBefore(): void
    {
        $this->assertSame(PlanStatusEnum::ACTIVE->value, $this->plan()->status);
    }

    /**
     * Demanding approval part-way through has to hold the work too. Setting the flag alone used to
     * change nothing: the plan stayed active and the loop kept dispatching underneath it.
     */
    public function testAskingForApprovalMidFlightHoldsARunningPlan(): void
    {
        $plan = $this->plan();
        $this->assertSame(PlanStatusEnum::ACTIVE->value, $plan->status);

        $held = new UpdatePlanAction(
            $plan,
            PlanData::forUpdate(
                $plan,
                app(Apps::class),
                $this->human()->getCurrentCompany(),
                ['requires_human_approval' => true],
            ),
        )->execute();

        $this->assertSame(PlanStatusEnum::AWAITING_APPROVAL->value, $held->status);
    }

    /** An approved plan is not sent back for approval every time it is saved. */
    public function testAnAlreadyApprovedPlanIsNotReheld(): void
    {
        $plan = $this->plan(requiresApproval: true, originUser: $this->human());
        $approved = new ApprovePlanAction($plan, $this->human(), approved: true)->execute();

        $resaved = new UpdatePlanAction(
            $approved,
            PlanData::forUpdate(
                $approved,
                app(Apps::class),
                $this->human()->getCurrentCompany(),
                ['status' => PlanStatusEnum::ACTIVE->value],
            ),
        )->execute();

        $this->assertSame(PlanStatusEnum::ACTIVE->value, $resaved->status);
    }

    /** The exact move plan 25667 made: the agent that asked signs it off itself. */
    public function testTheRequestingAgentCannotApproveItsOwnPlan(): void
    {
        $creator = $this->makeAgent();
        $plan = $this->plan(requiresApproval: true, creator: $creator, originUser: $this->human());

        $this->expectException(ValidationException::class);

        new ApprovePlanAction($plan, $creator->user, approved: true)->execute();
    }

    /** The assignee is no more entitled to sign off its own work than the requester is. */
    public function testTheAssignedAgentCannotApproveItsOwnPlan(): void
    {
        $worker = $this->makeAgent();
        $plan = $this->plan(requiresApproval: true, originUser: $this->human());
        $plan->agent_id = $worker->getId();
        $plan->saveQuietly();

        $this->expectException(ValidationException::class);

        new ApprovePlanAction($plan->fresh(), $worker->user, approved: true)->execute();
    }

    /** A person signing it off is the whole point of the gate. */
    public function testAHumanCanApproveAndTheWorkResumes(): void
    {
        $creator = $this->makeAgent();
        $plan = $this->plan(requiresApproval: true, creator: $creator, originUser: $this->human());

        $approved = new ApprovePlanAction($plan, $this->human(), approved: true)->execute();

        $this->assertSame(PlanStatusEnum::ACTIVE->value, $approved->status);
        $this->assertNotNull($approved->approved_at);
    }

    /**
     * With nobody to ask, refusing would wedge the plan forever — a cron- or swarm-made plan has no
     * conversation and no human behind it.
     */
    public function testAnAgentMayApproveItsOwnPlanWhenNoHumanIsInTheLoop(): void
    {
        $creator = $this->makeAgent();
        $plan = $this->plan(requiresApproval: true, creator: $creator);

        $this->assertNull($plan->origin_users_id, 'Precondition: nobody to ask.');

        $approved = new ApprovePlanAction($plan, $creator->user, approved: true)->execute();

        $this->assertSame(PlanStatusEnum::ACTIVE->value, $approved->status);
    }

    /** The deliberate opt-out, for a flow where self-sign-off is the design rather than an accident. */
    public function testSelfApprovalIsAllowedWhenExplicitlyPermitted(): void
    {
        $creator = $this->makeAgent();
        $plan = $this->plan(requiresApproval: true, creator: $creator, originUser: $this->human());

        $approved = new ApprovePlanAction(
            $plan,
            $creator->user,
            approved: true,
            allowSelfApproval: true,
        )->execute();

        $this->assertSame(PlanStatusEnum::ACTIVE->value, $approved->status);
    }

    /**
     * Approving has to move the WORK, not just the plan's status.
     *
     * Plan 26238: the UI approval fired the wake one second after the click, the worker looked at its
     * board, found its own task still `blocked` with "Awaiting budget approval of $500", and
     * `PlanContinuationAction` returned BLOCK. The plan was approved and running with nothing to do,
     * until a human said so in a comment.
     */
    public function testApprovingReleasesTheTasksThatWereWaitingOnIt(): void
    {
        $plan = $this->plan(requiresApproval: true, originUser: $this->human());
        $task = $this->makeTask($plan, TaskStatusEnum::BLOCKED, blockedReason: 'Awaiting budget approval of $500');

        new ApprovePlanAction($plan, $this->human(), approved: true)->execute();

        $task = $task->fresh();

        $this->assertSame(TaskStatusEnum::PENDING->value, $task->status);
        $this->assertNull($task->blocked_reason);
    }

    /** A rejection cancels the plan — its blocked work must not be quietly put back in play. */
    public function testRejectingDoesNotReleaseBlockedTasks(): void
    {
        $plan = $this->plan(requiresApproval: true, originUser: $this->human());
        $task = $this->makeTask($plan, TaskStatusEnum::BLOCKED, blockedReason: 'Awaiting budget approval');

        new ApprovePlanAction($plan, $this->human(), approved: false)->execute();

        $this->assertSame(TaskStatusEnum::BLOCKED->value, $task->fresh()->status);
    }

    /** Work already finished is not reopened by an approval landing after it. */
    public function testApprovingLeavesFinishedWorkAlone(): void
    {
        $plan = $this->plan(requiresApproval: true, originUser: $this->human());
        $done = $this->makeTask($plan, TaskStatusEnum::DONE);

        new ApprovePlanAction($plan, $this->human(), approved: true)->execute();

        $this->assertSame(TaskStatusEnum::DONE->value, $done->fresh()->status);
    }

    /** The conversational route: a person says yes, the agent records it against THEM. */
    public function testTheToolRecordsAHumansDecisionAndResumesTheWork(): void
    {
        $creator = $this->makeAgent();
        $plan = $this->plan(requiresApproval: true, creator: $creator, originUser: $this->human());

        $result = new ApproveNervousSystemPlanTool()
            ->withContext(app(Apps::class), $this->human()->getCurrentCompany(), $creator->user, $creator)
            ->forRequestingUser($this->human())(plan_id: $plan->getId(), approved: true, review_outcome: 'Budget approved.');

        $this->assertTrue($result['approved']);
        $this->assertSame(PlanStatusEnum::ACTIVE->value, $result['status']);
        $this->assertSame($this->human()->getId(), $result['reviewed_by_users_id']);
    }

    /**
     * The trap this tool is written around: `requestingHuman()` falls back to the turn's actor, and on
     * every wake surface that actor IS the agent's own user. Trusting it would hand the PM the
     * approval it is supposed to be asking a person for.
     */
    public function testTheToolRefusesWhenTheOnlyIdentifiedUserIsTheAgentItself(): void
    {
        $creator = $this->makeAgent();
        $plan = $this->plan(requiresApproval: true, creator: $creator, originUser: $this->human());

        $result = new ApproveNervousSystemPlanTool()
            ->withContext(app(Apps::class), $this->human()->getCurrentCompany(), $creator->user, $creator)
            ->forRequestingUser($creator->user)(plan_id: $plan->getId(), approved: true);

        $this->assertFalse($result['approved']);
        $this->assertSame(
            PlanStatusEnum::AWAITING_APPROVAL->value,
            $plan->fresh()->status,
            'A refused approval must leave the plan held.'
        );
    }

    /** Nobody identified at all — a heartbeat wake with no person in the room. */
    public function testTheToolRefusesWithNoIdentifiedPerson(): void
    {
        $creator = $this->makeAgent();
        $plan = $this->plan(requiresApproval: true, creator: $creator, originUser: $this->human());

        $result = new ApproveNervousSystemPlanTool()
            ->withContext(app(Apps::class), $this->human()->getCurrentCompany(), $creator->user, $creator)
            ->forRequestingUser(null)(plan_id: $plan->getId(), approved: true);

        $this->assertFalse($result['approved']);
        $this->assertSame(PlanStatusEnum::AWAITING_APPROVAL->value, $plan->fresh()->status);
    }

    /** A rejection is terminal — the plan is cancelled, not quietly retried. */
    public function testARejectionCancelsThePlan(): void
    {
        $creator = $this->makeAgent();
        $plan = $this->plan(requiresApproval: true, creator: $creator, originUser: $this->human());

        $result = new ApproveNervousSystemPlanTool()
            ->withContext(app(Apps::class), $this->human()->getCurrentCompany(), $creator->user, $creator)
            ->forRequestingUser($this->human())(plan_id: $plan->getId(), approved: false, review_outcome: 'Too expensive.');

        $this->assertSame(PlanStatusEnum::CANCELLED->value, $result['status']);
    }

    private function plan(
        bool $requiresApproval = false,
        ?Agent $creator = null,
        ?Users $originUser = null,
    ): Plan {
        $plan = new CreatePlanAction(
            new PlanData(
                app: app(Apps::class),
                company: $this->human()->getCurrentCompany(),
                title: 'Approval ' . fake()->unique()->lexify('?????'),
                planType: 'project_work',
                user: $this->human(),
                status: PlanStatusEnum::ACTIVE,
                requiresHumanApproval: $requiresApproval,
                createdByAgent: $creator,
            ),
        )->execute();

        if ($originUser !== null) {
            $plan->origin_users_id = $originUser->getId();
            $plan->saveQuietly();
        }

        return $plan->fresh();
    }

    private function human(): Users
    {
        return static::$cachedUser;
    }
}
