<?php

declare(strict_types=1);

namespace Tests\Guild\Organizations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Actions\MergeOrganizationsAction;
use Kanvas\Guild\Organizations\Models\Organization;
use Tests\TestCase;

final class OrganizationEmailDomainTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'accounting', 'intelligence', 'ecosystem'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();
    }

    public function testAddEmailDomainsIsAdditiveAndDeduplicated(): void
    {
        $domain = $this->uniqueDomain();
        $organization = $this->seedOrganization('Domain Org');

        $organization->addEmailDomains([$domain, 'ceo@' . $domain]);
        $organization->addEmailDomains(['other-' . $domain, 'someone@gmail.com', null]);

        $this->assertSame([$domain, 'other-' . $domain], $organization->emailDomains());
        $this->assertSame(
            [$domain, 'other-' . $domain],
            $organization->get(Organization::EMAIL_DOMAINS_CUSTOM_FIELD),
        );
    }

    public function testLookupMatchesTheWholeDomainNotASubstring(): void
    {
        $domain = $this->uniqueDomain();
        $this->seedOrganization('Prefixed')->addEmailDomains(['x' . $domain]);

        $this->assertTrue(Organization::findByEmailDomain($this->kanvasApp, $this->company, 'jane@' . $domain)->isEmpty());
    }

    public function testFindByEmailDomainMatchesOnlyTheTenantsOrganizations(): void
    {
        $domain = $this->uniqueDomain();
        $organization = $this->seedOrganization('Domain Owner');
        $organization->addEmailDomains([$domain]);

        $matches = Organization::findByEmailDomain($this->kanvasApp, $this->company, 'jane@' . $domain);

        $this->assertSame([$organization->getId()], $matches->pluck('id')->all());

        $otherCompany = Companies::where('id', '!=', $this->company->getId())->firstOrFail();
        $this->assertTrue(Organization::findByEmailDomain($this->kanvasApp, $otherCompany, 'jane@' . $domain)->isEmpty());
        $this->assertTrue(Organization::findByEmailDomain($this->kanvasApp, $this->company, 'jane@gmail.com')->isEmpty());
    }

    public function testFindByEmailDomainReturnsEveryOrganizationSharingTheDomain(): void
    {
        $domain = $this->uniqueDomain();
        $this->seedOrganization('Brand A')->addEmailDomains([$domain]);
        $this->seedOrganization('Brand B')->addEmailDomains([$domain]);

        $this->assertCount(2, Organization::findByEmailDomain($this->kanvasApp, $this->company, 'x@' . $domain));
    }

    public function testMergeCarriesTheSourceDomainsToTheTarget(): void
    {
        $domain = $this->uniqueDomain();
        $source = $this->seedOrganization('Merge Source');
        $source->addEmailDomains([$domain]);
        $target = $this->seedOrganization('Merge Target');

        new MergeOrganizationsAction(
            source: $source,
            target: $target,
            user: static::$cachedUser,
        )->execute();

        $matches = Organization::findByEmailDomain($this->kanvasApp, $this->company, 'x@' . $domain);
        $this->assertSame([$target->getId()], $matches->pluck('id')->all());
    }

    public function testLinkPersonFallsThroughAnAmbiguousEmailToAnUnambiguousOne(): void
    {
        $shared = $this->uniqueDomain();
        $this->seedOrganization('Shared A')->addEmailDomains([$shared]);
        $this->seedOrganization('Shared B')->addEmailDomains([$shared]);
        $unique = $this->uniqueDomain();
        $owner = $this->seedOrganization('Unique Owner');
        $owner->addEmailDomains([$unique]);

        $people = $this->seedPeople();
        $people->addEmail('jane@' . $shared);
        $people->addEmail('jane@' . $unique);

        $matches = Organization::linkPersonByEmailDomain($people);

        $this->assertSame([$owner->getId()], $matches->pluck('id')->all());
        $this->assertSame([$owner->getId()], $people->organizations()->pluck('organizations.id')->all());
    }

    public function testLinkPersonReturnsCandidatesAndLinksNothingWhenTheDomainIsShared(): void
    {
        $shared = $this->uniqueDomain();
        $this->seedOrganization('Shared A')->addEmailDomains([$shared]);
        $this->seedOrganization('Shared B')->addEmailDomains([$shared]);
        $people = $this->seedPeople();
        $people->addEmail('jane@' . $shared);

        $this->assertCount(2, Organization::linkPersonByEmailDomain($people));
        $this->assertFalse($people->organizations()->exists());
    }

    private function seedPeople(): People
    {
        return People::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create();
    }

    private function uniqueDomain(): string
    {
        return 'dom' . uniqid() . '.test';
    }

    private function seedOrganization(string $name): Organization
    {
        return Organization::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => static::$cachedUser->getId(),
            'name' => $name . ' ' . uniqid(),
        ]);
    }
}
