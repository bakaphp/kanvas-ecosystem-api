<?php

declare(strict_types=1);

namespace Tests\Approvals;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Approvals\Actions\ApproveAction;
use Kanvas\Approvals\Actions\CancelApprovalAction;
use Kanvas\Approvals\Actions\DelegateApprovalAction;
use Kanvas\Approvals\Actions\RejectAction;
use Kanvas\Approvals\Actions\RequestApprovalAction;
use Kanvas\Approvals\Actions\SystemApproveAction;
use Kanvas\Approvals\Enums\ApprovalDecisionEnum;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Enums\ApprovalOutcomeEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Services\ApproverSelfAssignService;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;
use Tests\Approvals\Fixtures\ApprovableOrganization;
use Tests\Approvals\Fixtures\RecordingApprovalHandler;
use Tests\TestCase;

final class ApproveActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'intelligence'];

    public function setUp(): void
    {
        parent::setUp();

        RecordingApprovalHandler::reset();

        SystemModules::firstOrCreate([
            'model_name' => ApprovableOrganization::class,
            'apps_id' => app(Apps::class)->getId(),
        ], [
            'name' => 'Approvable Organization',
            'slug' => 'approvable-organization',
        ]);
    }

    public function test_a_single_approval_closes_a_one_of_one_step(): void
    {
        [$request, $approvers] = $this->chain([['required_approvals' => 1, 'approvers' => 1]]);

        $result = new ApproveAction($request, $approvers[0])->execute();

        $this->assertSame(ApprovalOutcomeEnum::APPROVED, $result->outcome);
        $this->assertSame(ApprovalStatusEnum::APPROVED, $request->refresh()->status);
        $this->assertSame($approvers[0]->getId(), $request->resolved_by_users_id);
    }

    public function test_any_one_of_several_approvers_can_close_a_one_of_n_step(): void
    {
        [$request, $approvers] = $this->chain([['required_approvals' => 1, 'approvers' => 3]]);

        $result = new ApproveAction($request, $approvers[2])->execute();

        $this->assertSame(ApprovalOutcomeEnum::APPROVED, $result->outcome);
    }

    public function test_a_two_of_three_step_stays_pending_until_quorum(): void
    {
        [$request, $approvers] = $this->chain([['required_approvals' => 2, 'approvers' => 3]]);

        $first = new ApproveAction($request, $approvers[0])->execute();

        $this->assertSame(ApprovalOutcomeEnum::STILL_PENDING, $first->outcome);
        $this->assertSame(1, $first->have);
        $this->assertSame(2, $first->needed);
        $this->assertSame(ApprovalStatusEnum::PENDING, $request->refresh()->status);
        $this->assertFalse(RecordingApprovalHandler::$ran, 'The handler must not run before quorum.');

        $second = new ApproveAction($request, $approvers[1])->execute();

        $this->assertSame(ApprovalOutcomeEnum::APPROVED, $second->outcome);
        $this->assertTrue(RecordingApprovalHandler::$ran);
    }

    public function test_clearing_step_one_advances_to_step_two_and_activates_its_approvers(): void
    {
        [$request, $approvers] = $this->chain([
            ['required_approvals' => 1, 'approvers' => 1],
            ['required_approvals' => 1, 'approvers' => 2],
        ]);

        $result = new ApproveAction($request, $approvers[0])->execute();

        $this->assertSame(ApprovalOutcomeEnum::ADVANCED, $result->outcome);
        $this->assertSame(2, $result->step);
        $this->assertSame(ApprovalStatusEnum::PENDING, $request->refresh()->status);
        $this->assertSame(2, $request->current_step);
        $this->assertCount(2, $request->pendingApproverEmails());
        $this->assertFalse(RecordingApprovalHandler::$ran, 'Advancing a step is not completing.');
    }

    public function test_a_step_two_approver_cannot_sign_while_step_one_is_live(): void
    {
        [$request, $approvers] = $this->chain([
            ['required_approvals' => 1, 'approvers' => 1],
            ['required_approvals' => 1, 'approvers' => 1],
        ]);

        $this->expectException(ValidationException::class);

        new ApproveAction($request, $approvers[1])->execute();
    }

    public function test_someone_who_is_not_an_approver_is_refused(): void
    {
        [$request] = $this->chain([['required_approvals' => 1, 'approvers' => 1]]);
        $stranger = $this->seedUser('stranger');

        $this->expectException(ValidationException::class);

        new ApproveAction($request, $stranger)->execute();
    }

    public function test_approving_twice_is_refused(): void
    {
        [$request, $approvers] = $this->chain([['required_approvals' => 2, 'approvers' => 3]]);

        new ApproveAction($request, $approvers[0])->execute();

        $this->expectException(ValidationException::class);

        new ApproveAction($request->refresh(), $approvers[0])->execute();
    }

    public function test_approving_an_already_closed_request_is_refused(): void
    {
        [$request, $approvers] = $this->chain([['required_approvals' => 1, 'approvers' => 2]]);

        new ApproveAction($request, $approvers[0])->execute();

        $this->expectException(ValidationException::class);

        new ApproveAction($request->refresh(), $approvers[1])->execute();
    }

    /**
     * Several approvers are DM'd at once so whoever is free responds first — two of them closing the
     * final step in the same instant is routine, and without the conditional claim both would run the
     * handler and push the bill twice.
     */
    public function test_a_concurrent_close_runs_the_handler_exactly_once(): void
    {
        [$request, $approvers] = $this->chain([['required_approvals' => 1, 'approvers' => 2]]);

        // Two callers holding their own model instance, both seeing status=pending.
        $first = ApprovalRequest::findOrFail($request->getId());
        $second = ApprovalRequest::findOrFail($request->getId());

        $resultOne = new ApproveAction($first, $approvers[0])->execute();
        $resultTwo = new ApproveAction($second, $approvers[1])->execute();

        $this->assertSame(ApprovalOutcomeEnum::APPROVED, $resultOne->outcome);
        $this->assertSame(ApprovalOutcomeEnum::ALREADY_RESOLVED, $resultTwo->outcome);
        $this->assertSame(1, RecordingApprovalHandler::$runs, 'The handler must run exactly once.');
    }

    public function test_the_handler_result_is_returned_to_the_caller(): void
    {
        [$request, $approvers] = $this->chain([['required_approvals' => 1, 'approvers' => 1]]);

        $result = new ApproveAction($request, $approvers[0])->execute();

        $this->assertSame('acme-reference', $result->handlerResult['reference']);
    }

    public function test_a_throwing_handler_reports_the_failure_without_undoing_the_approval(): void
    {
        RecordingApprovalHandler::$throw = true;
        [$request, $approvers] = $this->chain([['required_approvals' => 1, 'approvers' => 1]]);

        $result = new ApproveAction($request, $approvers[0])->execute();

        $this->assertSame(ApprovalOutcomeEnum::APPROVED, $result->outcome);
        $this->assertSame('downstream exploded', $result->handlerResult['handler_error']);
        $this->assertSame(ApprovalStatusEnum::APPROVED, $request->refresh()->status);
    }

    public function test_rejection_ends_the_request_and_skips_everyone_still_waiting(): void
    {
        [$request, $approvers] = $this->chain([
            ['required_approvals' => 1, 'approvers' => 2],
            ['required_approvals' => 1, 'approvers' => 1],
        ]);

        $result = new RejectAction($request, $approvers[0], 'wrong amount')->execute();

        $this->assertSame(ApprovalOutcomeEnum::REJECTED, $result->outcome);
        $this->assertSame(ApprovalStatusEnum::REJECTED, $request->refresh()->status);
        $this->assertSame('wrong amount', $request->reason);
        $this->assertSame(
            0,
            $request->approvers()->whereIn('decision', [
                ApprovalDecisionEnum::PENDING,
                ApprovalDecisionEnum::WAITING,
            ])->count()
        );
        $this->assertFalse(RecordingApprovalHandler::$ran);
    }

    public function test_reject_policy_step_survives_a_no_while_quorum_is_still_reachable(): void
    {
        [$request, $approvers] = $this->chain(
            [['required_approvals' => 2, 'approvers' => 3]],
            ['reject_policy' => 'step']
        );

        $result = new RejectAction($request, $approvers[0], 'not mine')->execute();

        $this->assertSame(ApprovalOutcomeEnum::STILL_PENDING, $result->outcome);
        $this->assertSame(ApprovalStatusEnum::PENDING, $request->refresh()->status);
    }

    public function test_reject_policy_step_ends_the_request_once_quorum_is_unreachable(): void
    {
        [$request, $approvers] = $this->chain(
            [['required_approvals' => 2, 'approvers' => 3]],
            ['reject_policy' => 'step']
        );

        new RejectAction($request, $approvers[0], 'no')->execute();
        $result = new RejectAction($request->refresh(), $approvers[1], 'also no')->execute();

        $this->assertSame(ApprovalOutcomeEnum::REJECTED, $result->outcome);
    }

    public function test_delegation_keeps_the_original_row_and_gives_the_delegate_the_turn(): void
    {
        [$request, $approvers] = $this->chain([['required_approvals' => 1, 'approvers' => 1]]);
        $delegate = $this->seedUser('delegate');

        new DelegateApprovalAction($request, $approvers[0], $delegate)->execute();

        $original = $request->approvers()->where('users_id', $approvers[0]->getId())->first();
        $this->assertSame(ApprovalDecisionEnum::DELEGATED, $original->decision);
        $this->assertSame($delegate->getId(), $original->delegated_to_users_id);

        $result = new ApproveAction($request->refresh(), $delegate)->execute();
        $this->assertSame(ApprovalOutcomeEnum::APPROVED, $result->outcome);
    }

    public function test_the_original_approver_cannot_act_after_delegating(): void
    {
        [$request, $approvers] = $this->chain([['required_approvals' => 1, 'approvers' => 1]]);
        $delegate = $this->seedUser('post-delegate');

        new DelegateApprovalAction($request, $approvers[0], $delegate)->execute();

        $this->expectException(ValidationException::class);

        new ApproveAction($request->refresh(), $approvers[0])->execute();
    }

    public function test_delegating_to_yourself_is_refused(): void
    {
        [$request, $approvers] = $this->chain([['required_approvals' => 1, 'approvers' => 1]]);

        $this->expectException(ValidationException::class);

        new DelegateApprovalAction($request, $approvers[0], $approvers[0])->execute();
    }

    public function test_system_approval_records_no_human_and_marks_rows_auto_approved(): void
    {
        [$request] = $this->chain([['required_approvals' => 1, 'approvers' => 1]]);

        $result = new SystemApproveAction($request, 'timed out after 72h')->execute();

        $this->assertSame(ApprovalOutcomeEnum::APPROVED, $result->outcome);

        $request->refresh();
        $this->assertNull($request->resolved_by_users_id, 'No person approved this.');
        $this->assertTrue((bool) $request->metadata['system_approved']);
        $this->assertSame(
            ApprovalDecisionEnum::AUTO_APPROVED,
            $request->approvers()->first()->decision
        );
        $this->assertTrue(RecordingApprovalHandler::$ran);
    }

    public function test_system_approval_requires_a_reason(): void
    {
        [$request] = $this->chain([['required_approvals' => 1, 'approvers' => 1]]);

        $this->expectException(ValidationException::class);

        new SystemApproveAction($request, '   ')->execute();
    }

    public function test_cancelling_closes_the_request_without_running_the_handler(): void
    {
        [$request] = $this->chain([['required_approvals' => 1, 'approvers' => 1]]);

        $result = new CancelApprovalAction($request, 'invoice voided')->execute();

        $this->assertSame(ApprovalOutcomeEnum::CANCELLED, $result->outcome);
        $this->assertSame(ApprovalStatusEnum::CANCELLED, $request->refresh()->status);
        $this->assertNull($request->resolved_by_users_id);
        $this->assertFalse(RecordingApprovalHandler::$ran);
    }

    public function test_cancelling_an_already_closed_request_is_a_no_op(): void
    {
        [$request, $approvers] = $this->chain([['required_approvals' => 1, 'approvers' => 1]]);
        new ApproveAction($request, $approvers[0])->execute();

        $result = new CancelApprovalAction($request->refresh())->execute();

        $this->assertSame(ApprovalOutcomeEnum::ALREADY_RESOLVED, $result->outcome);
        $this->assertSame(ApprovalStatusEnum::APPROVED, $request->refresh()->status);
    }

    public function test_the_trait_shortcut_approves_through_the_same_action(): void
    {
        [$request, $approvers, $entity] = $this->chain([['required_approvals' => 1, 'approvers' => 1]]);

        $result = $entity->approve($approvers[0], 'looks right');

        $this->assertSame(ApprovalOutcomeEnum::APPROVED, $result->outcome);
        $this->assertSame($request->getId(), $result->request->getId());
    }

    public function test_the_trait_shortcut_fails_when_nothing_is_pending(): void
    {
        $entity = $this->seedEntity('No Pending Corp');

        $this->expectException(ValidationException::class);

        $entity->approve($this->seedUser('nobody'));
    }

    /**
     * The decision itself has to be auditable, not just the state change it caused: the domain's own
     * event (scribe.bill.received) says the bill moved, never who signed for it or who else declined.
     */
    public function test_an_approval_is_recorded_in_the_ledger_with_its_approver_trail(): void
    {
        [$request, $approvers] = $this->chain([['required_approvals' => 1, 'approvers' => 2]]);

        new ApproveAction($request, $approvers[0])->execute();

        $event = $this->ledgerEventFor($request, 'approvals.approved');

        $this->assertNotNull($event, 'Approving must leave a ledger entry.');
        $this->assertSame('User', $event->actor_type);
        $this->assertSame($approvers[0]->getId(), $event->actor_id);
        $this->assertSame('approved', $event->payload['status']);
        $this->assertCount(2, $event->payload['approvers'], 'The trail records everyone who was asked.');
    }

    public function test_a_rejection_is_recorded_naming_who_declined(): void
    {
        [$request, $approvers] = $this->chain([['required_approvals' => 1, 'approvers' => 1]]);

        new RejectAction($request, $approvers[0], 'amount is wrong')->execute();

        $event = $this->ledgerEventFor($request, 'approvals.rejected');

        $this->assertNotNull($event);
        $this->assertSame($approvers[0]->getId(), $event->actor_id);
        $this->assertSame('amount is wrong', $event->payload['reason']);
    }

    public function test_a_cancellation_is_recorded_even_though_it_fires_no_workflow(): void
    {
        [$request] = $this->chain([['required_approvals' => 1, 'approvers' => 1]]);

        new CancelApprovalAction($request, 'bill voided')->execute();

        $this->assertNotNull(
            $this->ledgerEventFor($request, 'approvals.approval_cancelled'),
            'A withdrawn request must still be auditable.'
        );
    }

    private function ledgerEventFor(ApprovalRequest $request, string $eventType): ?Event
    {
        return Event::query()
            ->where('source_entity_type', ApprovalRequest::class)
            ->where('source_entity_id', $request->getId())
            ->where('event_type', $eventType)
            ->latest('id')
            ->first();
    }

    /**
     * @return array{0: ApprovalRequest, 1: list<Users>, 2: ApprovableOrganization}
     */
    /**
     * The default, and the one that matters most: adopting approvals must never quietly make every
     * admin an approver of everything. On a bill the approver list IS the control.
     */
    public function test_an_owner_cannot_decide_a_policy_that_did_not_opt_into_authority_override(): void
    {
        [$request] = $this->chain([['required_approvals' => 1, 'approvers' => 1]]);

        /** @var Users $owner */
        $owner = auth()->user();
        $this->assertTrue($request->company->isOwner($owner));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not an approver');

        new ApproveAction($request, $owner)->execute();
    }

    public function test_an_owner_may_decide_when_the_policy_opts_into_authority_override(): void
    {
        [$request] = $this->chain(
            [['required_approvals' => 1, 'approvers' => 1]],
            ['allow_authority_override' => true]
        );

        /** @var Users $owner */
        $owner = auth()->user();

        $result = new ApproveAction($request, $owner)->execute();

        $this->assertSame(ApprovalOutcomeEnum::APPROVED, $result->outcome);
        $this->assertSame($owner->getId(), $request->refresh()->resolved_by_users_id);

        // Self-assigned, not waived: the decision is still backed by an approver row, and the row says
        // it was taken on authority rather than because anyone asked.
        $this->assertSame(
            ApprovalDecisionEnum::APPROVED,
            $request->approvers()->where('users_id', $owner->getId())->first()?->decision
        );
        $this->assertSame(
            ApproverSelfAssignService::OWNER,
            $request->metadata['self_assigned_approvers'][0]['authority'] ?? null
        );
    }

    /**
     * Self-assignment must not resurrect a decision. Under `reject_policy: step` a single "no" leaves
     * the request open, so without the guard the same person could come back through the authority
     * path and have their recorded rejection quietly rewritten into an approval.
     */
    public function test_authority_override_cannot_overwrite_a_decision_its_holder_already_made(): void
    {
        [$request] = $this->chain(
            [['required_approvals' => 2, 'approvers' => 2]],
            ['allow_authority_override' => true, 'reject_policy' => 'step']
        );

        /** @var Users $owner */
        $owner = auth()->user();

        new RejectAction($request, $owner, 'not this one')->execute();
        $this->assertSame(ApprovalStatusEnum::PENDING, $request->refresh()->status, 'quorum is still reachable');
        $this->assertSame(
            ApprovalDecisionEnum::REJECTED,
            $request->approvers()->where('users_id', $owner->getId())->first()?->decision
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not an approver');

        new ApproveAction($request, $owner)->execute();
    }

    private function chain(array $stepSpecs, array $policyOverrides = []): array
    {
        $entity = $this->seedEntity('Chain Corp ' . uniqid());
        $approvers = [];
        $steps = [];

        foreach ($stepSpecs as $index => $spec) {
            $stepUsers = [];

            for ($i = 0; $i < ($spec['approvers'] ?? 1); $i++) {
                $user = $this->seedUser('step' . ($index + 1) . '-' . $i);
                $approvers[] = $user;
                $stepUsers[] = $user->getId();
            }

            $steps[] = [
                'step' => $index + 1,
                'resolver' => 'explicit_users',
                'config' => ['users_id' => $stepUsers],
                'required_approvals' => $spec['required_approvals'] ?? 1,
            ];
        }

        $policy = ApprovalPolicy::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'system_modules_id' => new ApprovableOrganization()->approvalSystemModuleId(),
            'approval_type' => 'approve_test_entity',
            'steps' => $steps,
            'handler' => RecordingApprovalHandler::class,
            'trigger' => ApprovalTriggerEnum::MANUAL,
            ...$policyOverrides,
        ]);

        $request = new RequestApprovalAction(
            entity: $entity,
            policy: $policy,
            origin: ApprovalOriginEnum::AGENT,
        )->execute()->refresh();

        return [$request, $approvers, $entity];
    }

    private function seedUser(string $prefix): Users
    {
        return Users::factory()->create(['email' => $prefix . '-' . uniqid() . '@example.test']);
    }

    private function seedEntity(string $name): ApprovableOrganization
    {
        $user = auth()->user();

        return ApprovableOrganization::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
            'name' => $name,
            'address' => '',
            'total_employees' => 0,
        ]);
    }
}
