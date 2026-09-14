<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationPeople;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateLeadTool;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * The agent path used to stamp `lead.organization_id` with a raw post-save fill, which skipped the
 * person↔organization link CreateLeadAction does — the lead pointed at Brooklinen while John Horten
 * was never a member of it.
 */
final class CreateLeadOrganizationLinkTest extends TestCase
{
    private Apps $kanvasApp;
    private Companies $company;
    private Users $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();
    }

    private function tool(): CreateLeadTool
    {
        return new CreateLeadTool($this->kanvasApp, $this->company, $this->user);
    }

    private function uniqueName(string $prefix): string
    {
        return $prefix . '-' . fake()->unique()->uuid();
    }

    private function seedOrganization(string $name, ?int $companiesId = null): Organization
    {
        return Organization::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $companiesId ?? $this->company->getId(),
            'users_id' => $this->user->getId(),
            'name' => $name,
            'address' => '',
            'total_employees' => 0,
        ]);
    }

    private function assertPersonBelongsToOrganization(int $leadId, int $organizationId): void
    {
        $lead = Lead::getById($leadId);

        $this->assertSame($organizationId, (int) $lead->organization_id, 'lead should point at the organization');
        $this->assertTrue(
            OrganizationPeople::query()
                ->where('organizations_id', $organizationId)
                ->where('peoples_id', $lead->people_id)
                ->exists(),
            'the lead\'s person should be a member of the organization'
        );
    }

    public function testOrganizationNameCreatesTheOrganizationAndAddsThePersonToIt(): void
    {
        $orgName = $this->uniqueName('Brooklinen');

        $result = $this->tool()(
            title: 'Brooklinen - John Horten',
            firstname: 'John',
            lastname: 'Horten',
            email: fake()->unique()->safeEmail(),
            organization_name: $orgName,
        );

        $this->assertArrayHasKey('lead_id', $result, json_encode($result));

        $organization = Organization::query()
            ->where('name', $orgName)
            ->where('companies_id', $this->company->getId())
            ->where('apps_id', $this->kanvasApp->getId())
            ->first();

        $this->assertNotNull($organization, "organization {$orgName} should have been created");
        $this->assertPersonBelongsToOrganization((int) $result['lead_id'], $organization->getId());
    }

    public function testOrganizationNameReusesAnExistingOrganization(): void
    {
        $existing = $this->seedOrganization($this->uniqueName('Acme'));

        $result = $this->tool()(
            title: 'Acme - repeat contact',
            firstname: 'Jane',
            lastname: 'Doe',
            email: fake()->unique()->safeEmail(),
            organization_name: $existing->name,
        );

        $this->assertSame($existing->getId(), (int) $result['organization_id']);
        $this->assertSame(
            1,
            Organization::query()
                ->where('name', $existing->name)
                ->where('companies_id', $this->company->getId())
                ->count(),
            'an existing organization must be reused, not duplicated'
        );
        $this->assertPersonBelongsToOrganization((int) $result['lead_id'], $existing->getId());
    }

    public function testOrganizationIdAlsoAddsThePersonToTheOrganization(): void
    {
        $organization = $this->seedOrganization($this->uniqueName('Globex'));

        $result = $this->tool()(
            title: 'Globex - referral',
            firstname: 'Sam',
            lastname: 'Rivera',
            email: fake()->unique()->safeEmail(),
            organization_id: $organization->getId(),
        );

        $this->assertPersonBelongsToOrganization((int) $result['lead_id'], $organization->getId());
    }

    public function testOrganizationIdFromAnotherCompanyIsRefusedAndNoLeadIsCreated(): void
    {
        $foreign = $this->seedOrganization(
            $this->uniqueName('Initech'),
            companiesId: $this->company->getId() + 9999
        );

        $result = $this->tool()(
            title: 'Initech - cross tenant',
            firstname: 'Peter',
            lastname: 'Gibbons',
            email: fake()->unique()->safeEmail(),
            organization_id: $foreign->getId(),
        );

        // The `error` key is what Laravel\CreateLeadTool::handle() checks to surface a failure —
        // a different shape here would be silently JSON-encoded as if the call had succeeded.
        $this->assertArrayNotHasKey('lead_id', $result);
        $this->assertStringContainsString('does not exist for this company', $result['error']);

        $this->assertFalse(
            OrganizationPeople::query()->where('organizations_id', $foreign->getId())->exists(),
            'nothing may be written against another tenant\'s organization'
        );
    }

    public function testOrganizationIdWinsOverOrganizationName(): void
    {
        $organization = $this->seedOrganization($this->uniqueName('Umbrella'));
        $ignoredName = $this->uniqueName('Ignored');

        $result = $this->tool()(
            title: 'Umbrella - both given',
            firstname: 'Alice',
            lastname: 'Abernathy',
            email: fake()->unique()->safeEmail(),
            organization_id: $organization->getId(),
            organization_name: $ignoredName,
        );

        $this->assertSame($organization->getId(), (int) $result['organization_id']);
        $this->assertFalse(
            Organization::query()
                ->where('name', $ignoredName)
                ->where('companies_id', $this->company->getId())
                ->exists(),
            'organization_name must be ignored when an explicit id resolved'
        );
    }

    public function testLeadWithoutAnyOrganizationStaysUnlinked(): void
    {
        $result = $this->tool()(
            title: 'No org lead',
            firstname: 'Solo',
            lastname: 'Contact',
            email: fake()->unique()->safeEmail(),
        );

        $this->assertArrayHasKey('lead_id', $result, json_encode($result));
        $this->assertEmpty($result['organization_id']);
    }
}
