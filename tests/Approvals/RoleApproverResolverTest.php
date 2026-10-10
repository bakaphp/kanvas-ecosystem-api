<?php

declare(strict_types=1);

namespace Tests\Approvals;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Kanvas\AccessControlList\Actions\AssignRoleAction;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Approvals\Actions\RequestApprovalAction;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Approvals\Notifications\ApprovalRequestedNotification;
use Kanvas\Approvals\Resolvers\RoleApproverResolver;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Companies\Models\Companies;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Actions\AssignCompanyAction;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Tests\Approvals\Fixtures\ApprovableOrganization;
use Tests\TestCase;

/**
 * Covers the approach adopted instead of tag-gated approvers: gate approvers (and, by extension,
 * notifications — NotifyApproversAction reads the same approver rows this resolver writes) with the
 * EXISTING 'role' resolver and the EXISTING role-assignment mutation. No new resolver, no change to
 * the generic tag mutations — both already shipped.
 *
 * Only test_the_real_mutation_assigns_a_role_the_resolver_then_picks_up goes through
 * assignRoleToUser end to end, against the already-authenticated test user (same fixture
 * RolesTest::testAddRoleUser uses) rather than a freshly created teammate. The other tests call
 * AssignRoleAction directly (the same action the mutation calls after its own user lookup) and then
 * flushEcosystemWrites(). Two reasons:
 *
 * 1. assignRoleToUser's own lookup — UsersRepository::getUserOf{Company,App}ById — raw-joins `users`
 *    (connection `mysql`) against `users_associated_company` (connection `ecosystem`). In production
 *    both connections point at the same database so this is invisible; under DatabaseTransactions each
 *    connection opens its own transaction, so a membership row created earlier in the SAME test is not
 *    yet visible to a query running on `mysql`'s own transaction.
 * 2. RoleApproverResolver itself, via UsersRepository::getCompanyAppUserByRole, has the identical
 *    cross-connection join against `users_associated_apps` — so even calling AssignRoleAction directly
 *    isn't enough; the membership write has to be visible across connections before the resolver reads
 *    it back.
 *
 * Neither is a bug in the mutation or the resolver — it is a test-isolation artifact of raw joins
 * across two Laravel connections that happen to share one physical database, combined with MySQL's
 * default REPEATABLE READ isolation (a transaction's snapshot is fixed at its first query, so even a
 * genuine commit on the other connection stays invisible to it). flushEcosystemWrites() commits and
 * reopens BOTH connections mid-test so each takes a fresh snapshot that includes the other's writes;
 * DatabaseTransactions' own tearDown still rolls back whatever is written after the reopen, same
 * trade-off tests/CLAUDE.md already accepts elsewhere for cross-connection quirks (the committed rows
 * use uniqid() emails/names, so nothing collides across runs).
 */
final class RoleApproverResolverTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'intelligence'];

    private Apps $currentApp;
    private Companies $currentCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->currentCompany = auth()->user()->getCurrentCompany();

        SystemModules::firstOrCreate([
            'model_name' => ApprovableOrganization::class,
            'apps_id' => $this->currentApp->getId(),
        ], [
            'name' => 'Approvable Organization',
            'slug' => 'approvable-organization',
        ]);
    }

    public function test_the_real_mutation_assigns_a_role_the_resolver_then_picks_up(): void
    {
        $actingUser = auth()->user();
        $roleId = $this->createRoleViaGraphQL();

        $this->graphQL('
            mutation assignRoleToUser($userId: ID!, $roleIds: [ID!]!) {
                assignRoleToUser(userId: $userId, roleIds: $roleIds)
            }
        ', [
            'userId' => (string) $actingUser->getId(),
            'roleIds' => [$roleId],
        ])->assertJson(['data' => ['assignRoleToUser' => true]]);

        $roleName = RolesRepository::getByIdFromCompany((int) $roleId, $this->currentCompany, $this->currentApp)->name;

        $entity = $this->seedEntity();
        $result = new RoleApproverResolver()->resolve($entity, ['role' => $roleName]);

        $this->assertContains($actingUser->getId(), $result->pluck('id')->all());
    }

    public function test_wired_into_a_policy_only_notifies_role_holders(): void
    {
        Notification::fake();

        $teammate = $this->makeTeammate();
        $untagged = $this->makeTeammate();
        $roleName = $this->createAndAssignRole($teammate);
        $this->flushEcosystemWrites();

        $entity = $this->seedEntity();

        $policy = ApprovalPolicy::create([
            'apps_id' => $this->currentApp->getId(),
            'companies_id' => $this->currentCompany->getId(),
            'system_modules_id' => $entity->approvalSystemModuleId(),
            'approval_type' => 'approve_role_test_entity',
            'steps' => [['resolver' => 'role', 'config' => ['role' => $roleName], 'required_approvals' => 1]],
            'trigger' => ApprovalTriggerEnum::MANUAL,
        ]);

        $request = new RequestApprovalAction(
            entity: $entity,
            policy: $policy,
            origin: ApprovalOriginEnum::AGENT,
        )->execute()->refresh();

        $this->assertSame([$teammate->email], $request->pendingApproverEmails());
        Notification::assertSentTo($teammate, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($untagged, ApprovalRequestedNotification::class);
    }

    /**
     * CreateRoleAction and RolesRepository::getByNameFromCompany both resolve Bouncer scope with
     * RolesEnums::getScope($app) alone — the $company argument is accepted but never folded into the
     * scope string. A role assignment is therefore app-wide, not per company the way a tag on
     * UsersAssociatedApps was: assigning "Approvers" makes someone an approver in every company they
     * belong to, not just the one the admin was looking at. Pinned here so nobody "fixes" the unused
     * $company param into doing something it visibly doesn't today, and so a future reader doesn't
     * assume role-based approvers can be scoped the way the tag design was.
     */
    public function test_a_role_assigned_once_approves_in_every_company_the_user_belongs_to(): void
    {
        $teammate = $this->makeTeammate();
        $roleName = $this->createAndAssignRole($teammate);

        $otherCompany = Companies::factory()->create();
        UsersAssociatedApps::create([
            'users_id' => $teammate->getId(),
            'apps_id' => $this->currentApp->getId(),
            'companies_id' => $otherCompany->getId(),
            'user_role' => 'users',
            'user_active' => 1,
        ]);
        $this->flushEcosystemWrites();

        $entity = $this->seedEntity();
        $result = new RoleApproverResolver()->resolve($entity, ['role' => $roleName]);

        $this->assertSame([$teammate->getId()], $result->pluck('id')->all());
    }

    /**
     * Committing only `ecosystem` is not enough: MySQL's default REPEATABLE READ isolation means the
     * `mysql` connection's transaction (opened once at setUp, by DatabaseTransactions) took its
     * consistent-read snapshot before this test ran, so a commit on another connection afterwards still
     * will not appear to it. Both sides have to commit-and-reopen so `mysql` takes a fresh snapshot too.
     */
    private function flushEcosystemWrites(): void
    {
        foreach (['ecosystem', 'mysql'] as $connection) {
            DB::connection($connection)->commit();
            DB::connection($connection)->beginTransaction();
        }
    }

    private function createRoleViaGraphQL(): string
    {
        $response = $this->graphQL('
            mutation createRole($input: RoleInput!) {
                createRole(input: $input) { id name }
            }
        ', [
            'input' => ['name' => 'Approvers-' . uniqid(), 'permissions' => []],
        ]);

        $role = $response->json('data.createRole');
        $this->assertNotNull($role, (string) $response->getContent());

        return (string) $role['id'];
    }

    private function createAndAssignRole(Users $user): string
    {
        $roleId = $this->createRoleViaGraphQL();
        $role = RolesRepository::getByIdFromCompany((int) $roleId, $this->currentCompany, $this->currentApp);

        new AssignRoleAction($user, $role, $this->currentApp)->execute();

        return $role->name;
    }

    private function seedEntity(): ApprovableOrganization
    {
        $user = auth()->user();

        return ApprovableOrganization::create([
            'apps_id' => $this->currentApp->getId(),
            'companies_id' => $this->currentCompany->getId(),
            'users_id' => $user->getId(),
            'name' => 'Role Resolver Corp ' . uniqid(),
            'address' => '',
            'total_employees' => 0,
        ]);
    }

    private function makeTeammate(): Users
    {
        $user = new RegisterUsersAction(RegisterInput::from([
            'email' => 'role-resolver-' . uniqid() . '@example.test',
            'password' => bin2hex(random_bytes(8)),
            'firstname' => 'Role',
            'lastname' => 'Teammate',
        ]))->execute();

        $branch = $this->currentCompany->branch ?? $this->currentCompany->branches()->first();
        $role = RolesRepository::getByNameFromCompany(RolesEnums::USER->value, $this->currentCompany, $this->currentApp);

        new AssignCompanyAction($user, $branch, $role, $this->currentApp)->execute();

        return $user;
    }
}
