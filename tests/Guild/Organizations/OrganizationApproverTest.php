<?php

declare(strict_types=1);

namespace Tests\Guild\Organizations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationApprover;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class OrganizationApproverTest extends TestCase
{
    use DatabaseTransactions;

    // OrganizationApprover lives on 'crm' and linkApproverEmail() can create Users on 'mysql' —
    // without declaring both, DatabaseTransactions only rolls back 'mysql' and the crm rows commit
    // for real, leaking into unrelated tests later in the same run. See tests/CLAUDE.md.
    protected array $connectionsToTransact = ['mysql', 'crm'];

    public function test_emails_for_is_empty_when_no_approvers_are_linked(): void
    {
        $organization = $this->seedOrganization('No Approver Test Corp');

        $this->assertSame([], OrganizationApprover::emailsFor($organization));
    }

    public function test_an_organization_can_have_more_than_one_approver(): void
    {
        $organization = $this->seedOrganization('Multi Approver Test Corp');
        $approverOne = Users::factory()->create(['email' => 'approver-one-' . uniqid() . '@example.test']);
        $approverTwo = Users::factory()->create(['email' => 'approver-two-' . uniqid() . '@example.test']);

        OrganizationApprover::addApproverToOrganization($organization, $approverOne);
        OrganizationApprover::addApproverToOrganization($organization, $approverTwo);

        $emails = OrganizationApprover::emailsFor($organization);

        $this->assertCount(2, $emails);
        $this->assertContains($approverOne->email, $emails);
        $this->assertContains($approverTwo->email, $emails);
    }

    public function test_adding_the_same_approver_twice_does_not_duplicate_it(): void
    {
        $organization = $this->seedOrganization('Duplicate Approver Test Corp');
        $approver = Users::factory()->create(['email' => 'dup-approver-' . uniqid() . '@example.test']);

        OrganizationApprover::addApproverToOrganization($organization, $approver);
        OrganizationApprover::addApproverToOrganization($organization, $approver);

        $this->assertCount(1, OrganizationApprover::emailsFor($organization));
    }

    public function test_removing_an_approver_drops_it_from_the_list(): void
    {
        $organization = $this->seedOrganization('Removable Approver Test Corp');
        $approver = Users::factory()->create(['email' => 'removable-approver-' . uniqid() . '@example.test']);

        OrganizationApprover::addApproverToOrganization($organization, $approver);
        $this->assertCount(1, OrganizationApprover::emailsFor($organization));

        OrganizationApprover::removeApproverFromOrganization($organization, $approver);
        $this->assertSame([], OrganizationApprover::emailsFor($organization));
    }

    public function test_link_approver_email_reuses_an_existing_kanvas_user(): void
    {
        $organization = $this->seedOrganization('Existing User Approver Test Corp');
        $existingUser = Users::factory()->create(['email' => 'existing-approver-' . uniqid() . '@example.test']);

        OrganizationApprover::linkApproverEmail($organization, $existingUser->email);

        $this->assertSame([$existingUser->email], OrganizationApprover::emailsFor($organization));
    }

    public function test_link_approver_email_creates_a_minimal_user_when_none_matches(): void
    {
        $organization = $this->seedOrganization('New User Approver Test Corp');
        $newEmail = 'brand-new-approver-' . uniqid() . '@example.test';

        $this->assertNull(Users::query()->where('email', $newEmail)->first());

        OrganizationApprover::linkApproverEmail($organization, $newEmail);

        $this->assertSame([$newEmail], OrganizationApprover::emailsFor($organization));
        $this->assertNotNull(Users::query()->where('email', $newEmail)->first());
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
