<?php

declare(strict_types=1);

namespace Tests\Approvals;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Approvals\Actions\ApproveAction;
use Kanvas\Approvals\Actions\RequestApprovalAction;
use Kanvas\Approvals\Actions\SystemRejectAction;
use Kanvas\Approvals\Enums\ApprovalDecisionEnum;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Enums\ApprovalOutcomeEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Users\Models\Users;
use Tests\Approvals\Fixtures\ApprovableOrganization;
use Tests\TestCase;

final class SystemRejectActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'intelligence'];

    public function test_system_rejection_records_no_human_and_closes_the_request(): void
    {
        [$request] = $this->chain();

        $result = new SystemRejectAction($request, 'superseded by a more recent request')->execute();

        $this->assertSame(ApprovalOutcomeEnum::REJECTED, $result->outcome);

        $request->refresh();
        $this->assertSame(ApprovalStatusEnum::REJECTED, $request->status);
        $this->assertNull($request->resolved_by_users_id, 'No person rejected this.');
        $this->assertSame('superseded by a more recent request', $request->reason);
        $this->assertSame(
            ApprovalDecisionEnum::SKIPPED,
            $request->approvers()->first()->decision
        );
    }

    public function test_system_rejection_requires_a_reason(): void
    {
        [$request] = $this->chain();

        $this->expectException(ValidationException::class);

        new SystemRejectAction($request, '   ')->execute();
    }

    /**
     * Matches SystemApproveAction's own behaviour: assertPending() fails loud for "you called this
     * on something not pending" — the graceful ALREADY_RESOLVED outcome is claimIfPending()'s race
     * safety net for two concurrent callers, not a substitute for this upfront check.
     */
    public function test_rejecting_an_already_resolved_request_throws(): void
    {
        [$request, $approvers] = $this->chain();
        new ApproveAction($request, $approvers[0])->execute();

        $this->expectException(ValidationException::class);

        new SystemRejectAction($request->refresh(), 'too late')->execute();
    }

    private function chain(): array
    {
        $entity = $this->seedEntity('System Reject Corp ' . uniqid());
        $user = $this->seedUser('approver');

        $policy = ApprovalPolicy::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'system_modules_id' => new ApprovableOrganization()->approvalSystemModuleId(),
            'approval_type' => 'approve_test_entity',
            'steps' => [[
                'step' => 1,
                'resolver' => 'explicit_users',
                'config' => ['users_id' => [$user->getId()]],
                'required_approvals' => 1,
            ]],
            'trigger' => ApprovalTriggerEnum::MANUAL,
        ]);

        $request = new RequestApprovalAction(
            entity: $entity,
            policy: $policy,
            origin: ApprovalOriginEnum::AGENT,
        )->execute()->refresh();

        return [$request, [$user], $entity];
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
