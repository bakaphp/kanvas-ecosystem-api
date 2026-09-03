<?php

declare(strict_types=1);

namespace Tests\Approvals;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Approvals\Actions\ApproveAction;
use Kanvas\Approvals\Actions\RejectAction;
use Kanvas\Approvals\Actions\RequestApprovalAction;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Neuron\Tools\Approvals\CheckApprovalStatusTool;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;
use Tests\Approvals\Fixtures\ApprovableOrganization;
use Tests\TestCase;

final class CheckApprovalStatusToolTest extends TestCase
{
    use DatabaseTransactions;

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

    public function test_it_reports_who_the_record_is_waiting_on(): void
    {
        [$request, $approvers] = $this->chain(requiredApprovals: 2, approverCount: 2);

        $result = $this->check($request);

        $this->assertTrue($result['found']);
        $this->assertFalse($result['approved']);
        $this->assertSame('pending', $result['status']);
        $this->assertSame(1, $result['current_step']);
        $this->assertSame(0, $result['approvals_at_current_step']);
        $this->assertSame(2, $result['approvals_needed_at_current_step']);
        $this->assertCount(2, $result['awaiting']);
        $this->assertContains($approvers[0]->email, $result['awaiting']);
    }

    public function test_it_reports_partial_progress_toward_quorum(): void
    {
        [$request, $approvers] = $this->chain(requiredApprovals: 2, approverCount: 2);

        new ApproveAction($request, $approvers[0])->execute();

        $result = $this->check($request);

        $this->assertFalse($result['approved']);
        $this->assertSame(1, $result['approvals_at_current_step']);
        $this->assertSame([$approvers[1]->email], $result['awaiting']);
        $this->assertStringContainsString('1 of 2', $result['message']);
    }

    public function test_it_reports_an_approved_record_and_who_signed(): void
    {
        [$request, $approvers] = $this->chain();

        new ApproveAction($request, $approvers[0])->execute();

        $result = $this->check($request);

        $this->assertTrue($result['approved']);
        $this->assertSame('approved', $result['status']);
        $this->assertSame($approvers[0]->email, $result['resolved_by']);
        $this->assertSame([], $result['awaiting']);
    }

    public function test_it_reports_a_rejection_with_its_reason(): void
    {
        [$request, $approvers] = $this->chain();

        new RejectAction($request, $approvers[0], 'amount is wrong')->execute();

        $result = $this->check($request);

        $this->assertFalse($result['approved']);
        $this->assertSame('rejected', $result['status']);
        $this->assertStringContainsString('amount is wrong', $result['message']);
    }

    /**
     * The trail, not just the outcome — "two were asked, one declined" is usually what is wanted.
     */
    public function test_it_returns_the_full_decision_trail(): void
    {
        [$request, $approvers] = $this->chain(requiredApprovals: 2, approverCount: 2);

        new ApproveAction($request, $approvers[0], 'looks right')->execute();

        $decisions = $this->check($request)['decisions'];

        $this->assertCount(2, $decisions);
        $this->assertSame('approved', $decisions[0]['decision']);
        $this->assertSame('looks right', $decisions[0]['comment']);
        $this->assertSame('pending', $decisions[1]['decision']);
    }

    public function test_it_flags_a_request_nobody_can_approve(): void
    {
        [$request] = $this->chain(approverCount: 0);

        $result = $this->check($request);

        $this->assertTrue($result['unassigned']);
        $this->assertStringContainsString('nobody is configured to approve it', $result['message']);
    }

    public function test_an_ungated_record_says_so_and_tells_the_agent_not_to_retry(): void
    {
        $result = new CheckApprovalStatusTool()
            ->withContext(app(Apps::class), auth()->user()->getCurrentCompany(), auth()->user())
            ->__invoke(target_type: 'bill', target_id: 999999999);

        $this->assertFalse($result['found']);
        $this->assertNull($result['approved']);
        $this->assertStringContainsString('Do not retry', $result['message']);
    }

    /**
     * @return array<string, mixed>
     */
    private function check(ApprovalRequest $request): array
    {
        return new CheckApprovalStatusTool()
            ->withContext(app(Apps::class), auth()->user()->getCurrentCompany(), auth()->user())
            ->__invoke(target_type: 'test_entity', target_id: $request->entity_id);
    }

    /**
     * @return array{0: ApprovalRequest, 1: list<Users>}
     */
    private function chain(int $requiredApprovals = 1, int $approverCount = 1): array
    {
        $entity = $this->seedEntity();
        $approvers = [];
        $ids = [];

        for ($i = 0; $i < $approverCount; $i++) {
            $user = Users::factory()->create(['email' => 'status-' . $i . '-' . uniqid() . '@example.test']);
            $approvers[] = $user;
            $ids[] = $user->getId();
        }

        $policy = ApprovalPolicy::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'system_modules_id' => new ApprovableOrganization()->approvalSystemModuleId(),
            'approval_type' => 'approve_test_entity',
            'steps' => [[
                'step' => 1,
                'resolver' => 'explicit_users',
                'config' => ['users_id' => $ids],
                'required_approvals' => $requiredApprovals,
            ]],
            'trigger' => ApprovalTriggerEnum::MANUAL,
        ]);

        $request = new RequestApprovalAction(
            entity: $entity,
            policy: $policy,
            origin: ApprovalOriginEnum::AGENT,
        )->execute()->refresh();

        return [$request, $approvers];
    }

    private function seedEntity(): ApprovableOrganization
    {
        $user = auth()->user();

        return ApprovableOrganization::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
            'name' => 'Status Corp ' . uniqid(),
            'address' => '',
            'total_employees' => 0,
        ]);
    }
}
