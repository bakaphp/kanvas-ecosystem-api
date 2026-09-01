<?php

declare(strict_types=1);

namespace Tests\Guild\Organizations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Organizations\Actions\AddApproverToOrganizationAction;
use Kanvas\Guild\Organizations\Actions\LinkApproverEmailToOrganizationAction;
use Kanvas\Guild\Organizations\Actions\RemoveApproverFromOrganizationAction;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationApprover;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class OrganizationApproverTest extends TestCase
{
    use DatabaseTransactions;

    // OrganizationApprover lives on 'crm' and LinkApproverEmailToOrganizationAction can create Users
    // on 'mysql' — without declaring both, DatabaseTransactions only rolls back 'mysql' and the crm
    // rows commit for real, leaking into unrelated tests later in the same run. See tests/CLAUDE.md.
    protected array $connectionsToTransact = ['mysql', 'crm'];

    public function test_emails_for_is_empty_when_no_approvers_are_linked(): void
    {
        $organization = $this->seedOrganization('No Approver Test Corp');

        $this->assertSame([], OrganizationApprover::emailsFor($organization));
    }

    public function test_an_organization_can_have_more_than_one_approver(): void
    {
        $organization = $this->seedOrganization('Multi Approver Test Corp');
        $approverOne = $this->seedUser('approver-one');
        $approverTwo = $this->seedUser('approver-two');

        new AddApproverToOrganizationAction($organization, $approverOne)->execute();
        new AddApproverToOrganizationAction($organization, $approverTwo)->execute();

        $emails = OrganizationApprover::emailsFor($organization);

        $this->assertCount(2, $emails);
        $this->assertContains($approverOne->email, $emails);
        $this->assertContains($approverTwo->email, $emails);
    }

    public function test_adding_the_same_approver_twice_does_not_duplicate_it(): void
    {
        $organization = $this->seedOrganization('Duplicate Approver Test Corp');
        $approver = $this->seedUser('dup-approver');

        new AddApproverToOrganizationAction($organization, $approver)->execute();
        new AddApproverToOrganizationAction($organization, $approver)->execute();

        $this->assertCount(1, OrganizationApprover::emailsFor($organization));
    }

    public function test_removing_an_approver_drops_it_from_the_list(): void
    {
        $organization = $this->seedOrganization('Removable Approver Test Corp');
        $approver = $this->seedUser('removable-approver');

        new AddApproverToOrganizationAction($organization, $approver)->execute();
        $this->assertCount(1, OrganizationApprover::emailsFor($organization));

        $this->assertTrue(new RemoveApproverFromOrganizationAction($organization, $approver)->execute());
        $this->assertSame([], OrganizationApprover::emailsFor($organization));
    }

    public function test_removing_an_approver_soft_deletes_rather_than_dropping_the_row(): void
    {
        $organization = $this->seedOrganization('Audit Trail Approver Test Corp');
        $approver = $this->seedUser('audit-approver');

        new AddApproverToOrganizationAction($organization, $approver)->execute();
        new RemoveApproverFromOrganizationAction($organization, $approver)->execute();

        $row = OrganizationApprover::query()
            ->where('organizations_id', $organization->getId())
            ->where('users_id', $approver->getId())
            ->first();

        $this->assertNotNull($row, 'Removing an approver must keep the row for audit, not delete it.');
        $this->assertTrue($row->is_deleted);
    }

    public function test_removing_an_approver_who_is_not_one_returns_false(): void
    {
        $organization = $this->seedOrganization('Never Approver Test Corp');
        $stranger = $this->seedUser('stranger');

        $this->assertFalse(new RemoveApproverFromOrganizationAction($organization, $stranger)->execute());
    }

    public function test_re_adding_a_removed_approver_revives_the_same_row(): void
    {
        $organization = $this->seedOrganization('Revived Approver Test Corp');
        $approver = $this->seedUser('revived-approver');

        new AddApproverToOrganizationAction($organization, $approver)->execute();
        new RemoveApproverFromOrganizationAction($organization, $approver)->execute();
        new AddApproverToOrganizationAction($organization, $approver)->execute();

        $this->assertSame([$approver->email], OrganizationApprover::emailsFor($organization));

        $rowCount = OrganizationApprover::query()
            ->where('organizations_id', $organization->getId())
            ->where('users_id', $approver->getId())
            ->count();

        $this->assertSame(1, $rowCount, 'The unique pair must be revived, not duplicated.');
    }

    public function test_link_approver_email_reuses_an_existing_kanvas_user(): void
    {
        $organization = $this->seedOrganization('Existing User Approver Test Corp');
        $existingUser = $this->seedUser('existing-approver');

        new LinkApproverEmailToOrganizationAction($organization, $existingUser->email)->execute();

        $this->assertSame([$existingUser->email], OrganizationApprover::emailsFor($organization));
    }

    public function test_link_approver_email_creates_a_minimal_user_when_none_matches(): void
    {
        $organization = $this->seedOrganization('New User Approver Test Corp');
        $newEmail = 'brand-new-approver-' . uniqid() . '@example.test';

        $this->assertNull(Users::query()->where('email', $newEmail)->first());

        new LinkApproverEmailToOrganizationAction($organization, $newEmail)->execute();

        $this->assertSame([$newEmail], OrganizationApprover::emailsFor($organization));
        $this->assertNotNull(Users::query()->where('email', $newEmail)->first());
    }

    public function test_the_organization_relation_only_exposes_live_approvers(): void
    {
        $organization = $this->seedOrganization('Relation Approver Test Corp');
        $kept = $this->seedUser('kept-approver');
        $removed = $this->seedUser('removed-approver');

        new AddApproverToOrganizationAction($organization, $kept)->execute();
        new AddApproverToOrganizationAction($organization, $removed)->execute();
        new RemoveApproverFromOrganizationAction($organization, $removed)->execute();

        $approvers = $organization->approvers()->get();

        $this->assertCount(1, $approvers);
        $this->assertSame($kept->getId(), $approvers->first()->users_id);
    }

    private function seedUser(string $prefix): Users
    {
        return Users::factory()->create(['email' => $prefix . '-' . uniqid() . '@example.test']);
    }

    private function seedOrganization(string $name): Organization
    {
        $app = app(Apps::class);
        $user = auth()->user();

        return Organization::create([
            'apps_id' => $app->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
            'name' => $name,
            'address' => '',
            'total_employees' => 0,
        ]);
    }
}
