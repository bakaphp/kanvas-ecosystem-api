<?php

declare(strict_types=1);

namespace Tests\Guild\Organizations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Services\FindOrganizationDuplicatesService;
use Tests\TestCase;

/**
 * Tests the duplicate-finder operator UI surface — exact_name + email_match SQL groupings, plus
 * the cross-dimension dedup so the same cluster doesn't appear twice when both dimensions hit.
 */
class FindOrganizationDuplicatesServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();
    }

    public function test_finds_exact_name_duplicates_case_insensitive(): void
    {
        // Unique cluster name so we don't collide with stale rows from prior live runs.
        $cluster = 'AcmeCluster' . uniqid();
        $a = $this->seedOrganization($cluster);
        $b = $this->seedOrganization(strtolower($cluster));    // lower-case duplicate
        $c = $this->seedOrganization('  ' . $cluster . '  '); // whitespace duplicate
        // unique row to ensure it's NOT in the cluster
        $this->seedOrganization('UNRELATED Vendor ' . uniqid());

        $groups = new FindOrganizationDuplicatesService()->generate(
            app: $this->kanvasApp,
            company: $this->company,
        );

        $acmeGroup = $this->findGroupContaining($groups, (int) $a->id);
        $this->assertNotNull($acmeGroup, 'exact_name should flag the three rows as one cluster.');
        $this->assertEqualsCanonicalizing(
            [(int) $a->id, (int) $b->id, (int) $c->id],
            $acmeGroup->member_ids,
        );
        $this->assertSame('exact_name', $acmeGroup->reason);
        $this->assertSame((int) $a->id, $acmeGroup->canonical_id, 'oldest id wins canonical.');
    }

    public function test_finds_email_duplicates_case_insensitive(): void
    {
        $unique = 'shared-' . uniqid() . '@vendor.test';
        $a = $this->seedOrganization('Vendor One', email: strtoupper($unique));
        $b = $this->seedOrganization('Vendor Two', email: $unique);

        $groups = new FindOrganizationDuplicatesService()->generate(
            app: $this->kanvasApp,
            company: $this->company,
        );

        $emailGroup = $this->findGroupContaining($groups, (int) $a->id);
        $this->assertNotNull($emailGroup);
        $this->assertSame('email_match', $emailGroup->reason);
        $this->assertEqualsCanonicalizing(
            [(int) $a->id, (int) $b->id],
            $emailGroup->member_ids,
        );
    }

    public function test_dedupes_groups_when_same_cluster_matches_multiple_dimensions(): void
    {
        $sharedEmail = 'overlap-' . uniqid() . '@vendor.test';
        $a = $this->seedOrganization('DoubleMatch Inc', email: $sharedEmail);
        $b = $this->seedOrganization('doublematch inc', email: $sharedEmail);

        $groups = new FindOrganizationDuplicatesService()->generate(
            app: $this->kanvasApp,
            company: $this->company,
        );

        $matches = array_filter(
            $groups,
            fn ($g) => $g->member_ids === [(int) $a->id, (int) $b->id],
        );
        $this->assertCount(
            1,
            $matches,
            'Same member set shouldn\'t appear under both exact_name AND email_match.',
        );
    }

    public function test_singletons_are_not_returned(): void
    {
        $org = $this->seedOrganization('Lonely Vendor ' . uniqid(), email: 'lonely-' . uniqid() . '@test.com');

        $groups = new FindOrganizationDuplicatesService()->generate(
            app: $this->kanvasApp,
            company: $this->company,
        );

        $appearances = 0;
        foreach ($groups as $group) {
            if (in_array((int) $org->id, $group->member_ids, true)) {
                $appearances++;
            }
        }
        $this->assertSame(0, $appearances, 'A non-duplicate org should never appear in any group.');
    }

    private function seedOrganization(string $name, ?string $email = null): Organization
    {
        return Organization::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => static::$cachedUser->getId(),
            'name' => $name,
            'email' => $email,
            'address' => '',
            'total_employees' => 0,
        ]);
    }

    /**
     * @param  list<\Kanvas\Guild\Organizations\DataTransferObject\OrganizationDuplicateGroup>  $groups
     */
    private function findGroupContaining(array $groups, int $memberId)
    {
        foreach ($groups as $group) {
            if (in_array($memberId, $group->member_ids, true)) {
                return $group;
            }
        }

        return null;
    }
}
