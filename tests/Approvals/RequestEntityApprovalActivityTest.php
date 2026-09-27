<?php

declare(strict_types=1);

namespace Tests\Approvals;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Approvals\Activities\RequestEntityApprovalActivity;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Organizations\Actions\AddApproverToOrganizationAction;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Approvals\Fixtures\ApprovableOrganization;
use Tests\TestCase;

final class RequestEntityApprovalActivityTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'intelligence'];

    public function test_opens_the_approval_type_named_in_params(): void
    {
        $entity = $this->seedEntity('Generic Activity Corp A');
        $this->linkApprover($entity);
        $this->seedPolicy('approve_generic', $entity);

        $result = $this->activity()->execute($entity, app(Apps::class), ['approval_type' => 'approve_generic']);

        $this->assertTrue($result['requested']);
        $request = ApprovalRequest::find($result['approval_request_id']);
        $this->assertSame('approve_generic', $request->approval_type);
    }

    public function test_missing_approval_type_param_is_a_no_op(): void
    {
        $entity = $this->seedEntity('Generic Activity Corp B');
        $this->linkApprover($entity);
        $this->seedPolicy('approve_generic', $entity);

        $result = $this->activity()->execute($entity, app(Apps::class), []);

        $this->assertFalse($result['requested']);
        $this->assertSame('missing approval_type param', $result['reason']);
    }

    public function test_entity_without_has_approvals_is_a_no_op(): void
    {
        $entity = Users::factory()->create();

        $result = $this->activity()->execute($entity, app(Apps::class), ['approval_type' => 'approve_generic']);

        $this->assertFalse($result['requested']);
        $this->assertSame('entity does not use HasApprovals', $result['reason']);
    }

    public function test_a_pending_request_of_the_same_type_blocks_a_new_one_by_default(): void
    {
        $entity = $this->seedEntity('Generic Activity Corp C');
        $this->linkApprover($entity);
        $this->seedPolicy('approve_generic', $entity);

        $first = $this->activity()->execute($entity, app(Apps::class), ['approval_type' => 'approve_generic']);
        $second = $this->activity()->execute($entity, app(Apps::class), ['approval_type' => 'approve_generic']);

        $this->assertTrue($first['requested']);
        $this->assertFalse($second['requested']);
        $this->assertSame('already pending', $second['reason']);
    }

    public function test_auto_reject_stale_pending_supersedes_the_older_request(): void
    {
        $entity = $this->seedEntity('Generic Activity Corp D');
        $this->linkApprover($entity);
        $this->seedPolicy('approve_generic', $entity);

        $first = $this->activity()->execute($entity, app(Apps::class), ['approval_type' => 'approve_generic']);
        $second = $this->activity()->execute($entity, app(Apps::class), [
            'approval_type' => 'approve_generic',
            'auto_reject_stale_pending' => true,
        ]);

        $this->assertTrue($second['requested']);
        $this->assertNotSame($first['approval_request_id'], $second['approval_request_id']);

        $old = ApprovalRequest::find($first['approval_request_id']);
        $this->assertSame('rejected', $old->status->value);
        $this->assertSame('Superseded by a more recent approval request', $old->reason);
    }

    public function test_payload_param_is_stored_on_the_request(): void
    {
        $entity = $this->seedEntity('Generic Activity Corp E');
        $this->linkApprover($entity);
        $this->seedPolicy('approve_generic', $entity);

        $result = $this->activity()->execute($entity, app(Apps::class), [
            'approval_type' => 'approve_generic',
            'payload' => ['reference' => 'from-a-rule'],
        ]);

        $request = ApprovalRequest::find($result['approval_request_id']);
        $this->assertSame('from-a-rule', $request->payload['reference']);
    }

    private function activity(): RequestEntityApprovalActivity
    {
        return new RequestEntityApprovalActivity(0, now()->toDateTimeString(), StoredWorkflow::make(), []);
    }

    private function seedPolicy(string $approvalType, ApprovableOrganization $entity): ApprovalPolicy
    {
        return ApprovalPolicy::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'system_modules_id' => $entity->approvalSystemModuleId(),
            'approval_type' => $approvalType,
            'steps' => [['resolver' => 'organization_approver', 'config' => [], 'required_approvals' => 1]],
            'trigger' => ApprovalTriggerEnum::MANUAL,
        ]);
    }

    private function linkApprover(ApprovableOrganization $entity): Users
    {
        $user = Users::factory()->create(['email' => 'approver-' . uniqid() . '@example.test']);
        new AddApproverToOrganizationAction($entity, $user)->execute();

        return $user;
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
