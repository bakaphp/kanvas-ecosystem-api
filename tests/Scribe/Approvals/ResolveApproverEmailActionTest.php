<?php

declare(strict_types=1);

namespace Tests\Scribe\Approvals;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationApprover;
use Kanvas\Scribe\Approvals\Actions\ResolveApproverEmailAction;
use Kanvas\Scribe\Approvals\Enums\OrganizationApproverCustomFieldEnum;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class ResolveApproverEmailActionTest extends TestCase
{
    use DatabaseTransactions;

    // Organization/OrganizationApprover live on 'crm' — without declaring it here,
    // DatabaseTransactions only rolls back 'mysql' and these rows commit for real. See tests/CLAUDE.md.
    protected array $connectionsToTransact = ['mysql', 'crm'];

    public function test_returns_empty_when_neither_organization_approvers_nor_the_legacy_field_are_set(): void
    {
        $organization = $this->seedOrganization('No Approver At All Corp');

        $this->assertSame([], ResolveApproverEmailAction::resolveForOrganization($organization));
    }

    public function test_falls_back_to_the_legacy_custom_field_when_no_organization_approvers_exist(): void
    {
        $organization = $this->seedOrganization('Legacy Field Only Corp');
        $organization->set(OrganizationApproverCustomFieldEnum::APPROVER_EMAIL->value, 'legacy@example.test');

        $this->assertSame(['legacy@example.test'], ResolveApproverEmailAction::resolveForOrganization($organization));
    }

    public function test_organization_approvers_take_priority_over_the_legacy_custom_field(): void
    {
        $organization = $this->seedOrganization('Both Configured Corp');
        $organization->set(OrganizationApproverCustomFieldEnum::APPROVER_EMAIL->value, 'legacy@example.test');

        $approver = Users::factory()->create(['email' => 'real-approver-' . uniqid() . '@example.test']);
        OrganizationApprover::addApproverToOrganization($organization, $approver);

        $emails = ResolveApproverEmailAction::resolveForOrganization($organization);

        $this->assertSame([$approver->email], $emails);
        $this->assertNotContains('legacy@example.test', $emails);
    }

    public function test_returns_every_organization_approver_email(): void
    {
        $organization = $this->seedOrganization('Two Approvers Corp');
        $approverOne = Users::factory()->create(['email' => 'approver-one-' . uniqid() . '@example.test']);
        $approverTwo = Users::factory()->create(['email' => 'approver-two-' . uniqid() . '@example.test']);
        OrganizationApprover::addApproverToOrganization($organization, $approverOne);
        OrganizationApprover::addApproverToOrganization($organization, $approverTwo);

        $emails = ResolveApproverEmailAction::resolveForOrganization($organization);

        $this->assertCount(2, $emails);
        $this->assertContains($approverOne->email, $emails);
        $this->assertContains($approverTwo->email, $emails);
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
