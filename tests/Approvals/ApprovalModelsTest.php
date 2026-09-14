<?php

declare(strict_types=1);

namespace Tests\Approvals;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Approvals\Enums\ApprovalDecisionEnum;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Exceptions\ApprovalRequiredException;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Apps\Models\Apps;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;
use Tests\Approvals\Fixtures\ApprovableOrganization;
use Tests\TestCase;

final class ApprovalModelsTest extends TestCase
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

    public function test_a_policy_orders_its_steps_and_defaults_the_step_number(): void
    {
        $policy = $this->seedPolicy([
            ['resolver' => 'role', 'config' => ['role' => 'Controller'], 'required_approvals' => 2, 'step' => 2],
            ['resolver' => 'organization_approver', 'config' => ['relation' => 'customer'], 'step' => 1],
        ]);

        $steps = $policy->approvalSteps();

        $this->assertCount(2, $steps);
        $this->assertSame(1, $steps[0]->step);
        $this->assertSame('organization_approver', $steps[0]->resolver);
        $this->assertSame(1, $steps[0]->requiredApprovals, 'required_approvals defaults to 1.');
        $this->assertSame(2, $steps[1]->step);
        $this->assertSame(2, $steps[1]->requiredApprovals);
        $this->assertSame(['role' => 'Controller'], $steps[1]->config);
    }

    public function test_a_step_without_an_explicit_number_falls_back_to_its_position(): void
    {
        $policy = $this->seedPolicy([
            ['resolver' => 'explicit_users', 'config' => ['users_id' => [1]]],
            ['resolver' => 'role', 'config' => ['role' => 'Finance']],
        ]);

        $steps = $policy->approvalSteps();

        $this->assertSame([1, 2], array_map(static fn ($s): int => $s->step, $steps));
    }

    public function test_step_at_returns_null_for_a_step_the_policy_does_not_define(): void
    {
        $policy = $this->seedPolicy([['resolver' => 'role', 'config' => ['role' => 'Finance']]]);

        $this->assertNotNull($policy->stepAt(1));
        $this->assertNull($policy->stepAt(2));
    }

    public function test_a_when_condition_survives_the_json_round_trip(): void
    {
        $policy = $this->seedPolicy([
            [
                'resolver' => 'role',
                'config' => ['role' => 'Controller'],
                'when' => ['field' => 'total_native', 'operator' => '>=', 'value' => 10000],
            ],
        ]);

        // assertEquals, not assertSame: the JSON round trip does not preserve key order.
        $this->assertEquals(
            ['field' => 'total_native', 'operator' => '>=', 'value' => 10000],
            $policy->fresh()->approvalSteps()[0]->when
        );
    }

    public function test_resolve_entity_returns_the_record_under_approval(): void
    {
        $organization = $this->seedEntity('Resolve Entity Corp');
        $request = $this->seedRequest($organization);

        $entity = $request->resolveEntity();

        $this->assertNotNull($entity);
        $this->assertSame($organization->getId(), $entity->getId());
    }

    public function test_resolve_entity_returns_null_when_the_record_is_gone(): void
    {
        $organization = $this->seedEntity('Vanished Entity Corp');
        $request = $this->seedRequest($organization);

        $organization->forceDelete();

        $this->assertNull($request->fresh()->resolveEntity());
    }

    public function test_next_live_step_skips_steps_that_were_never_asked(): void
    {
        $organization = $this->seedEntity('Next Step Corp');
        $request = $this->seedRequest($organization);

        $this->seedApprover($request, step: 1, decision: ApprovalDecisionEnum::APPROVED);
        $this->seedApprover($request, step: 2, decision: ApprovalDecisionEnum::SKIPPED);
        $this->seedApprover($request, step: 3, decision: ApprovalDecisionEnum::WAITING);

        $this->assertSame(3, $request->nextLiveStep(), 'A skipped step is not a live next step.');
    }

    public function test_next_live_step_is_null_when_nothing_is_left_to_ask(): void
    {
        $organization = $this->seedEntity('Last Step Corp');
        $request = $this->seedRequest($organization);

        $this->seedApprover($request, step: 1, decision: ApprovalDecisionEnum::APPROVED);
        $this->seedApprover($request, step: 2, decision: ApprovalDecisionEnum::SKIPPED);

        $this->assertNull($request->nextLiveStep());
    }

    public function test_pending_approver_emails_only_covers_the_current_step(): void
    {
        $organization = $this->seedEntity('Pending Emails Corp');
        $request = $this->seedRequest($organization);

        $current = $this->seedApprover($request, step: 1, decision: ApprovalDecisionEnum::PENDING);
        $this->seedApprover($request, step: 1, decision: ApprovalDecisionEnum::APPROVED);
        $this->seedApprover($request, step: 2, decision: ApprovalDecisionEnum::WAITING);

        $this->assertSame([$current->email], $request->pendingApproverEmails());
    }

    public function test_is_unassigned_reads_the_metadata_flag(): void
    {
        $organization = $this->seedEntity('Unassigned Corp');

        $this->assertFalse($this->seedRequest($organization)->isUnassigned());

        $stuck = $this->seedRequest($organization, ['metadata' => ['unassigned' => true]]);
        $this->assertTrue($stuck->fresh()->isUnassigned());
    }

    public function test_the_trait_finds_the_pending_request_across_connections(): void
    {
        $organization = $this->seedEntity('Cross Connection Corp');

        $this->assertNull($organization->pendingApproval());
        $this->assertFalse($organization->isApproved());

        $request = $this->seedRequest($organization);

        $this->assertSame($request->getId(), $organization->pendingApproval()?->getId());
    }

    public function test_the_trait_ignores_requests_belonging_to_another_entity(): void
    {
        $mine = $this->seedEntity('Mine Corp');
        $theirs = $this->seedEntity('Theirs Corp');

        $this->seedRequest($theirs);

        $this->assertNull($mine->pendingApproval());
    }

    public function test_approved_by_names_the_resolving_user(): void
    {
        $organization = $this->seedEntity('Approved By Corp');
        $approver = Users::factory()->create(['email' => 'resolver-' . uniqid() . '@example.test']);

        $this->seedRequest($organization, [
            'status' => ApprovalStatusEnum::APPROVED,
            'resolved_by_users_id' => $approver->getId(),
            'resolved_at' => now(),
        ]);

        $this->assertTrue($organization->isApproved());
        $this->assertSame($approver->getId(), $organization->approvedBy()?->getId());
    }

    public function test_approved_by_is_null_for_a_request_no_person_resolved(): void
    {
        $organization = $this->seedEntity('Auto Approved Corp');

        $this->seedRequest($organization, [
            'status' => ApprovalStatusEnum::APPROVED,
            'resolved_at' => now(),
            'metadata' => ['auto_approved' => true],
        ]);

        $this->assertTrue($organization->isApproved());
        $this->assertNull($organization->approvedBy(), 'A rule closed this, so there is no approver to name.');
    }

    public function test_assert_approved_throws_while_a_request_is_pending(): void
    {
        $organization = $this->seedEntity('Gated Corp');
        $this->seedRequest($organization);

        $this->expectException(ApprovalRequiredException::class);

        $organization->assertApproved();
    }

    public function test_assert_approved_passes_once_the_request_is_resolved(): void
    {
        $organization = $this->seedEntity('Ungated Corp');
        $request = $this->seedRequest($organization);

        $request->status = ApprovalStatusEnum::APPROVED;
        $request->save();

        $organization->assertApproved();

        $this->assertNull($organization->pendingApproval());
    }

    public function test_assert_approved_passes_for_an_entity_no_policy_ever_gated(): void
    {
        $this->seedEntity('Never Gated Corp')->assertApproved();

        $this->assertTrue(true);
    }

    public function test_soft_deleting_a_request_retires_its_approver_rows(): void
    {
        $organization = $this->seedEntity('Cascade Corp');
        $request = $this->seedRequest($organization);
        $this->seedApprover($request, step: 1, decision: ApprovalDecisionEnum::PENDING);

        $this->assertSame(1, $request->approvers()->count());

        $request->softDelete();

        $this->assertSame(0, $request->approvers()->count(), 'Approver rows must not outlive their request.');
    }

    public function test_entity_summary_describes_what_is_under_approval_without_dumping_the_model(): void
    {
        $organization = $this->seedEntity('Summary Corp');
        $summary = $this->seedRequest($organization)->entitySummary();

        $this->assertSame($organization->getId(), $summary['id']);
        $this->assertSame('Summary Corp', $summary['label']);
        $this->assertArrayHasKey('type', $summary);
        $this->assertSame(
            ['id', 'type', 'label'],
            array_keys($summary),
            'The summary is curated on purpose — a raw model dump would leak every column.'
        );
    }

    public function test_entity_summary_is_null_when_the_record_is_gone(): void
    {
        $organization = $this->seedEntity('Gone Summary Corp');
        $request = $this->seedRequest($organization);

        $organization->forceDelete();

        $this->assertNull($request->fresh()->entitySummary());
    }

    private function seedPolicy(array $steps): ApprovalPolicy
    {
        $app = app(Apps::class);

        return ApprovalPolicy::create([
            'apps_id' => $app->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'system_modules_id' => $this->systemModuleId(),
            'approval_type' => 'approve_test_entity',
            'steps' => $steps,
            'trigger' => ApprovalTriggerEnum::MANUAL,
        ]);
    }

    private function seedRequest(ApprovableOrganization $entity, array $overrides = []): ApprovalRequest
    {
        return ApprovalRequest::create([
            'apps_id' => $entity->apps_id,
            'companies_id' => $entity->companies_id,
            'system_modules_id' => $this->systemModuleId(),
            'entity_id' => $entity->getId(),
            'approval_type' => 'approve_test_entity',
            'origin' => ApprovalOriginEnum::AGENT,
            'status' => ApprovalStatusEnum::PENDING,
            'current_step' => 1,
            ...$overrides,
        ]);
    }

    private function seedApprover(
        ApprovalRequest $request,
        int $step,
        ApprovalDecisionEnum $decision
    ): object {
        $user = Users::factory()->create(['email' => 'approver-' . uniqid() . '@example.test']);

        return $request->approvers()->create([
            'users_id' => $user->getId(),
            'email' => $user->email,
            'step' => $step,
            'decision' => $decision,
        ]);
    }

    private function systemModuleId(): int
    {
        return new ApprovableOrganization()->approvalSystemModuleId();
    }

    private function seedEntity(string $name): ApprovableOrganization
    {
        $app = app(Apps::class);
        $user = auth()->user();

        return ApprovableOrganization::create([
            'apps_id' => $app->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
            'name' => $name,
            'address' => '',
            'total_employees' => 0,
        ]);
    }
}
