<?php

declare(strict_types=1);

namespace Tests\Approvals;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Approvals\Actions\RequestApprovalAction;
use Kanvas\Approvals\Enums\ApprovalDecisionEnum;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Repositories\ApprovalPolicyRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Organizations\Actions\AddApproverToOrganizationAction;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;
use Tests\Approvals\Fixtures\ApprovableOrganization;
use Tests\TestCase;

final class RequestApprovalActionTest extends TestCase
{
    use DatabaseTransactions;

    // 'ecosystem' is NOT optional here even though it points at the same database as 'mysql':
    // Kanvas\Models\BaseModel declares it, Laravel treats it as a separate connection, and without
    // it every approval_* row commits for real and later tests find the previous test's policies.
    // 'intelligence' carries the NervousSystem ledger events every approval decision emits.
    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'intelligence'];

    public function setUp(): void
    {
        parent::setUp();

        SystemModules::firstOrCreate([
            'model_name' => ApprovableOrganization::class,
            'apps_id' => app(Apps::class)->getId(),
        ], [
            'name' => 'Approvable Organization',
            'slug' => 'approvable-organization',
        ]);
    }

    public function test_a_single_step_policy_opens_a_pending_request_with_one_approver(): void
    {
        $entity = $this->seedEntity('Single Step Corp');
        $approver = $this->linkApprover($entity);

        $policy = $this->seedPolicy([
            ['resolver' => 'organization_approver', 'config' => [], 'required_approvals' => 1],
        ]);

        $request = $this->request($entity, $policy);

        $this->assertSame(ApprovalStatusEnum::PENDING, $request->status);
        $this->assertSame(1, $request->current_step);
        $this->assertSame([$approver->email], $request->pendingApproverEmails());
        $this->assertFalse($request->isUnassigned());
    }

    public function test_a_step_whose_condition_fails_is_written_as_skipped(): void
    {
        $entity = $this->seedEntity('Skipped Step Corp');
        $this->linkApprover($entity);
        $cfo = $this->seedUser('cfo');

        $policy = $this->seedPolicy([
            ['step' => 1, 'resolver' => 'organization_approver', 'config' => []],
            [
                'step' => 2,
                'resolver' => 'explicit_users',
                'config' => ['users_id' => [$cfo->getId()]],
                'when' => ['field' => 'total_employees', 'operator' => '>=', 'value' => 500],
            ],
        ]);

        $request = $this->request($entity, $policy);

        $stepTwo = $request->approvers()->where('step', 2)->first();

        $this->assertNotNull($stepTwo, 'A skipped step still records who was not asked.');
        $this->assertSame(ApprovalDecisionEnum::SKIPPED, $stepTwo->decision);
        $this->assertSame($cfo->getId(), $stepTwo->users_id);
        $this->assertNull($request->nextLiveStep());
    }

    public function test_a_step_whose_condition_passes_is_written_as_waiting_behind_step_one(): void
    {
        $entity = $this->seedEntity('Waiting Step Corp', totalEmployees: 900);
        $this->linkApprover($entity);
        $cfo = $this->seedUser('waiting-cfo');

        $policy = $this->seedPolicy([
            ['step' => 1, 'resolver' => 'organization_approver', 'config' => []],
            [
                'step' => 2,
                'resolver' => 'explicit_users',
                'config' => ['users_id' => [$cfo->getId()]],
                'when' => ['field' => 'total_employees', 'operator' => '>=', 'value' => 500],
            ],
        ]);

        $request = $this->request($entity, $policy);

        $this->assertSame(1, $request->current_step);
        $this->assertSame(
            ApprovalDecisionEnum::WAITING,
            $request->approvers()->where('step', 2)->first()->decision
        );
        $this->assertSame(2, $request->nextLiveStep());
    }

    public function test_a_multi_approver_step_writes_every_resolved_user_as_pending(): void
    {
        $entity = $this->seedEntity('Quorum Corp');
        $one = $this->linkApprover($entity, 'quorum-one');
        $two = $this->linkApprover($entity, 'quorum-two');

        $policy = $this->seedPolicy([
            ['resolver' => 'organization_approver', 'config' => [], 'required_approvals' => 2],
        ]);

        $emails = $this->request($entity, $policy)->pendingApproverEmails();

        $this->assertCount(2, $emails);
        $this->assertContains($one->email, $emails);
        $this->assertContains($two->email, $emails);
    }

    public function test_the_fallback_resolver_rescues_a_step_that_found_nobody(): void
    {
        $entity = $this->seedEntity('Fallback Corp');
        $fallbackUser = $this->seedUser('fallback');

        $policy = $this->seedPolicy(
            [['resolver' => 'organization_approver', 'config' => []]],
            [
                'fallback_resolver' => 'explicit_users',
                'fallback_config' => ['users_id' => [$fallbackUser->getId()]],
            ]
        );

        $request = $this->request($entity, $policy);

        $this->assertSame([$fallbackUser->email], $request->pendingApproverEmails());
        $this->assertFalse($request->isUnassigned());
    }

    public function test_a_request_nobody_can_approve_is_flagged_unassigned_not_silently_stuck(): void
    {
        $entity = $this->seedEntity('Unassignable Corp');

        $policy = $this->seedPolicy([['resolver' => 'organization_approver', 'config' => []]]);

        $request = $this->request($entity, $policy);

        $this->assertTrue($request->isUnassigned());
        $this->assertSame([], $request->pendingApproverEmails());
        $this->assertFalse((bool) $request->metadata['no_live_steps']);
    }

    public function test_a_policy_whose_every_step_is_skipped_reports_no_live_steps(): void
    {
        $entity = $this->seedEntity('Nothing To Ask Corp');
        $this->linkApprover($entity);

        $policy = $this->seedPolicy([
            [
                'resolver' => 'organization_approver',
                'config' => [],
                'when' => ['field' => 'total_employees', 'operator' => '>=', 'value' => 500],
            ],
        ]);

        $request = $this->request($entity, $policy);

        $this->assertTrue((bool) $request->metadata['no_live_steps']);
        $this->assertFalse($request->isUnassigned(), 'Nothing to ask is not the same as nobody to ask.');
        $this->assertSame(0, $request->current_step);
    }

    public function test_a_first_step_with_no_approvers_does_not_promote_the_second(): void
    {
        $entity = $this->seedEntity('No Promotion Corp');
        $cfo = $this->seedUser('no-promotion-cfo');

        // Step 1 applies but resolves nobody. Promoting step 2 would silently drop step 1's signature.
        $policy = $this->seedPolicy([
            ['step' => 1, 'resolver' => 'organization_approver', 'config' => []],
            ['step' => 2, 'resolver' => 'explicit_users', 'config' => ['users_id' => [$cfo->getId()]]],
        ]);

        $request = $this->request($entity, $policy);

        $this->assertSame(1, $request->current_step);
        $this->assertTrue($request->isUnassigned());
        $this->assertSame(
            ApprovalDecisionEnum::WAITING,
            $request->approvers()->where('step', 2)->first()->decision
        );
    }

    public function test_an_unknown_resolver_leaves_the_request_unassigned_rather_than_throwing(): void
    {
        $entity = $this->seedEntity('Bad Resolver Corp');

        $policy = $this->seedPolicy([['resolver' => 'does_not_exist', 'config' => []]]);

        $request = $this->request($entity, $policy);

        $this->assertTrue($request->isUnassigned());
    }

    public function test_the_legacy_custom_field_is_used_when_no_approver_rows_exist(): void
    {
        $entity = $this->seedEntity('Legacy Field Corp');
        $legacy = $this->seedUser('legacy-approver');
        $entity->set('ap_approver_email', $legacy->email);

        $policy = $this->seedPolicy([['resolver' => 'organization_approver', 'config' => []]]);

        $this->assertSame([$legacy->email], $this->request($entity, $policy)->pendingApproverEmails());
    }

    public function test_linked_approvers_take_priority_over_the_legacy_custom_field(): void
    {
        $entity = $this->seedEntity('Priority Corp');
        $linked = $this->linkApprover($entity, 'priority-linked');
        $entity->set('ap_approver_email', $this->seedUser('priority-legacy')->email);

        $policy = $this->seedPolicy([['resolver' => 'organization_approver', 'config' => []]]);

        $this->assertSame([$linked->email], $this->request($entity, $policy)->pendingApproverEmails());
    }

    public function test_expires_at_is_set_from_the_policy(): void
    {
        $entity = $this->seedEntity('Expiring Corp');
        $this->linkApprover($entity);

        $policy = $this->seedPolicy(
            [['resolver' => 'organization_approver', 'config' => []]],
            ['expires_after_hours' => 72]
        );

        $this->assertNotNull($this->request($entity, $policy)->expires_at);
    }

    public function test_a_company_policy_beats_an_app_wide_one(): void
    {
        $entity = $this->seedEntity('Policy Precedence Corp');

        $this->seedPolicy([['resolver' => 'role', 'config' => ['role' => 'AppWide']]], ['companies_id' => 0]);
        $companyPolicy = $this->seedPolicy([['resolver' => 'role', 'config' => ['role' => 'CompanySpecific']]]);

        $found = ApprovalPolicyRepository::findByType($entity, 'approve_test_entity');

        $this->assertSame($companyPolicy->getId(), $found?->getId());
    }

    public function test_no_policy_means_request_approval_returns_null(): void
    {
        $entity = $this->seedEntity('Ungoverned Corp');

        $this->assertNull($entity->requestApproval('approve_test_entity'));
        $this->assertNull($entity->pendingApproval());
    }

    public function test_request_approval_through_the_trait_records_the_origin(): void
    {
        $entity = $this->seedEntity('Trait Origin Corp');
        $this->linkApprover($entity);
        $this->seedPolicy([['resolver' => 'organization_approver', 'config' => []]]);

        $request = $entity->requestApproval(
            'approve_test_entity',
            payload: ['total_native' => 1200],
            origin: ApprovalOriginEnum::AGENT,
        );

        $this->assertNotNull($request);
        $this->assertSame(ApprovalOriginEnum::AGENT, $request->origin);
        $this->assertSame(1200, $request->payload['total_native']);
        $this->assertSame($request->getId(), $entity->pendingApproval()?->getId());
    }

    private function request(ApprovableOrganization $entity, ApprovalPolicy $policy): ApprovalRequest
    {
        return new RequestApprovalAction(
            entity: $entity,
            policy: $policy,
            origin: ApprovalOriginEnum::AGENT,
        )->execute()->refresh();
    }

    private function seedPolicy(array $steps, array $overrides = []): ApprovalPolicy
    {
        return ApprovalPolicy::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'system_modules_id' => new ApprovableOrganization()->approvalSystemModuleId(),
            'approval_type' => 'approve_test_entity',
            'steps' => $steps,
            'trigger' => ApprovalTriggerEnum::MANUAL,
            ...$overrides,
        ]);
    }

    private function linkApprover(ApprovableOrganization $entity, string $prefix = 'approver'): Users
    {
        $user = $this->seedUser($prefix);
        new AddApproverToOrganizationAction($entity, $user)->execute();

        return $user;
    }

    private function seedUser(string $prefix): Users
    {
        return Users::factory()->create(['email' => $prefix . '-' . uniqid() . '@example.test']);
    }

    private function seedEntity(string $name, int $totalEmployees = 0): ApprovableOrganization
    {
        $user = auth()->user();

        return ApprovableOrganization::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
            'name' => $name,
            'address' => '',
            'total_employees' => $totalEmployees,
        ]);
    }
}
