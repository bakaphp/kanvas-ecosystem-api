<?php

declare(strict_types=1);

namespace Tests\Approvals;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Organizations\Actions\AddApproverToOrganizationAction;
use Kanvas\Users\Models\Users;
use Tests\Approvals\Fixtures\ApprovableOrganization;
use Tests\TestCase;

/**
 * An entity can now carry more than one pending approval_type at once (e.g. a People with both
 * approve_people and approve_people_salesforce_create open together) — these tests exercise the
 * type-scoping added to HasApprovals::pendingApproval()/pendingApprovals()/approve()/reject() on the
 * generic ApprovableOrganization fixture, independent of the Salesforce feature that motivated it.
 */
final class PendingApprovalsByTypeTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'intelligence'];

    public function test_pending_approval_with_a_type_ignores_a_pending_request_of_another_type(): void
    {
        $entity = $this->seedEntity('Multi Type Corp A');
        $this->linkApprover($entity);
        $this->seedPolicy('approve_type_a', $entity);
        $this->seedPolicy('approve_type_b', $entity);

        $entity->requestApproval('approve_type_a');
        $entity->requestApproval('approve_type_b');

        $this->assertNull($entity->pendingApproval('approve_type_c'));
        $this->assertNotNull($entity->pendingApproval('approve_type_a'));
        $this->assertNotNull($entity->pendingApproval('approve_type_b'));
        $this->assertSame('approve_type_a', $entity->pendingApproval('approve_type_a')->approval_type);
    }

    public function test_pending_approvals_plural_returns_every_pending_request_optionally_filtered(): void
    {
        $entity = $this->seedEntity('Multi Type Corp B');
        $this->linkApprover($entity);
        $this->seedPolicy('approve_type_a', $entity);
        $this->seedPolicy('approve_type_b', $entity);

        $entity->requestApproval('approve_type_a');
        $entity->requestApproval('approve_type_b');

        $this->assertCount(2, $entity->pendingApprovals());
        $this->assertCount(1, $entity->pendingApprovals('approve_type_a'));
        $this->assertCount(0, $entity->pendingApprovals('approve_type_c'));
    }

    public function test_approving_one_type_leaves_the_other_type_still_pending(): void
    {
        $entity = $this->seedEntity('Multi Type Corp C');
        $approver = $this->linkApprover($entity);
        $this->seedPolicy('approve_type_a', $entity);
        $this->seedPolicy('approve_type_b', $entity);

        $entity->requestApproval('approve_type_a');
        $entity->requestApproval('approve_type_b');

        $entity->approve($approver, approvalType: 'approve_type_a');

        $this->assertNull($entity->pendingApproval('approve_type_a'));
        $this->assertNotNull($entity->pendingApproval('approve_type_b'));
    }

    public function test_rejecting_one_type_leaves_the_other_type_still_pending(): void
    {
        $entity = $this->seedEntity('Multi Type Corp D');
        $approver = $this->linkApprover($entity);
        $this->seedPolicy('approve_type_a', $entity);
        $this->seedPolicy('approve_type_b', $entity);

        $entity->requestApproval('approve_type_a');
        $entity->requestApproval('approve_type_b');

        $entity->reject($approver, 'not needed', approvalType: 'approve_type_a');

        $this->assertNull($entity->pendingApproval('approve_type_a'));
        $this->assertNotNull($entity->pendingApproval('approve_type_b'));
    }

    public function test_pending_approval_with_no_type_still_returns_the_latest_pending_of_any_type(): void
    {
        $entity = $this->seedEntity('Multi Type Corp E');
        $this->linkApprover($entity);
        $this->seedPolicy('approve_type_a', $entity);

        $entity->requestApproval('approve_type_a');

        $this->assertNotNull($entity->pendingApproval());
        $this->assertSame('approve_type_a', $entity->pendingApproval()->approval_type);
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
