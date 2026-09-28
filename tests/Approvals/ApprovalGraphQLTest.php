<?php

declare(strict_types=1);

namespace Tests\Approvals;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Approvals\Actions\RequestApprovalAction;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Apps\Models\Apps;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;
use Tests\Approvals\Fixtures\ApprovableOrganization;
use Tests\TestCase;

final class ApprovalGraphQLTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'intelligence'];

    private const APPROVE = '
        mutation approve($input: ApprovalDecisionInput!) {
            approveApprovalRequest(input: $input) { id status resolved_by { id } }
        }
    ';

    private const REJECT = '
        mutation reject($input: ApprovalDecisionInput!) {
            rejectApprovalRequest(input: $input) { id status reason }
        }
    ';

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

    public function testApproveThroughGraphQL(): void
    {
        $request = $this->seedRequest(auth()->user());

        $this->graphQL(self::APPROVE, ['input' => ['id' => (string) $request->getId()]])
            ->assertSuccessful()
            ->assertJson([
                'data' => [
                    'approveApprovalRequest' => [
                        'status' => 'approved',
                        'resolved_by' => ['id' => (string) auth()->user()->getId()],
                    ],
                ],
            ]);
    }

    public function testANonApproverIsRefused(): void
    {
        $request = $this->seedRequest($this->seedUser('somebody-else'));

        $this->graphQL(self::APPROVE, ['input' => ['id' => (string) $request->getId()]])
            ->assertGraphQLErrorMessage('You are not an approver for this request at its current step.');

        $this->assertSame(ApprovalStatusEnum::PENDING, $request->refresh()->status);
    }

    public function testRejectRequiresAReason(): void
    {
        $request = $this->seedRequest(auth()->user());

        $this->graphQL(self::REJECT, ['input' => ['id' => (string) $request->getId()]])
            ->assertGraphQLErrorMessage('A rejection must say why.');

        $this->assertSame(ApprovalStatusEnum::PENDING, $request->refresh()->status);
    }

    public function testRejectThroughGraphQL(): void
    {
        $request = $this->seedRequest(auth()->user());

        $this->graphQL(self::REJECT, [
            'input' => ['id' => (string) $request->getId(), 'reason' => 'amount is wrong'],
        ])->assertSuccessful()->assertJson([
            'data' => ['rejectApprovalRequest' => ['status' => 'rejected', 'reason' => 'amount is wrong']],
        ]);
    }

    public function testApprovalRequestsQueryExposesTheApproverTrail(): void
    {
        $request = $this->seedRequest(auth()->user());

        $this->graphQL('
            query approvalRequests($id: Mixed) {
                approvalRequests(where: { column: ID, operator: EQ, value: $id }) {
                    data { id status approval_type approvers { step decision user { id } } }
                }
            }
        ', ['id' => $request->getId()])
            ->assertSuccessful()
            ->assertJson([
                'data' => [
                    'approvalRequests' => [
                        'data' => [[
                            'status' => 'pending',
                            'approvers' => [['step' => 1, 'decision' => 'pending']],
                        ]],
                    ],
                ],
            ]);
    }

    public function testMyPendingApprovalsOnlyReturnsRequestsOnMyDesk(): void
    {
        $mine = $this->seedRequest(auth()->user());
        $theirs = $this->seedRequest($this->seedUser('not-me'));

        $response = $this->graphQL('
            query { myPendingApprovals { data { id } } }
        ')->assertSuccessful();

        $ids = array_column($response->json('data.myPendingApprovals.data'), 'id');

        $this->assertContains((string) $mine->getId(), $ids);
        $this->assertNotContains((string) $theirs->getId(), $ids);
    }

    /**
     * Queued behind a step that has not cleared is not the same as being asked.
     */
    public function testMyPendingApprovalsExcludesAStepIAmNotLiveOn(): void
    {
        $request = $this->seedRequest($this->seedUser('step-one'), secondStepUser: auth()->user());

        $response = $this->graphQL('query { myPendingApprovals { data { id } } }')->assertSuccessful();

        $this->assertNotContains(
            (string) $request->getId(),
            array_column($response->json('data.myPendingApprovals.data'), 'id')
        );
    }

    public function testCreateApprovalPolicyRejectsAnUnknownResolver(): void
    {
        $this->graphQL('
            mutation createApprovalPolicy($input: ApprovalPolicyInput!) {
                createApprovalPolicy(input: $input) { id }
            }
        ', [
            'input' => [
                'system_module_id' => (string) new ApprovableOrganization()->approvalSystemModuleId(),
                'approval_type' => 'approve_test_entity',
                'steps' => [['resolver' => 'nope', 'config' => []]],
            ],
        ])->assertGraphQLErrorMessage('Unknown approver resolver "nope".');
    }

    public function testCreateApprovalPolicyThroughGraphQL(): void
    {
        $this->graphQL('
            mutation createApprovalPolicy($input: ApprovalPolicyInput!) {
                createApprovalPolicy(input: $input) { id approval_type trigger reject_policy notify }
            }
        ', [
            'input' => [
                'system_module_id' => (string) new ApprovableOrganization()->approvalSystemModuleId(),
                'approval_type' => 'approve_graph_entity',
                'steps' => [['resolver' => 'role', 'config' => ['role' => 'Finance']]],
                'trigger' => 'on_create',
            ],
        ])->assertSuccessful()->assertJson([
            'data' => [
                'createApprovalPolicy' => [
                    'approval_type' => 'approve_graph_entity',
                    'trigger' => 'on_create',
                    'reject_policy' => 'any',
                    'notify' => 'all',
                ],
            ],
        ]);
    }

    private function seedRequest(Users $approver, ?Users $secondStepUser = null): ApprovalRequest
    {
        $entity = $this->seedEntity('GraphQL Corp ' . uniqid());

        $steps = [[
            'step' => 1,
            'resolver' => 'explicit_users',
            'config' => ['users_id' => [$approver->getId()]],
        ]];

        if ($secondStepUser !== null) {
            $steps[] = [
                'step' => 2,
                'resolver' => 'explicit_users',
                'config' => ['users_id' => [$secondStepUser->getId()]],
            ];
        }

        $policy = ApprovalPolicy::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'system_modules_id' => new ApprovableOrganization()->approvalSystemModuleId(),
            'approval_type' => 'approve_test_entity',
            'steps' => $steps,
            'trigger' => ApprovalTriggerEnum::MANUAL,
        ]);

        return new RequestApprovalAction(
            entity: $entity,
            policy: $policy,
            origin: ApprovalOriginEnum::UI,
        )->execute()->refresh();
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
