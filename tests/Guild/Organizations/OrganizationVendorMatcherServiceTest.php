<?php

declare(strict_types=1);

namespace Tests\Guild\Organizations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Services\OrganizationVendorMatcherService;
use Tests\TestCase;

final class OrganizationVendorMatcherServiceTest extends TestCase
{
    use DatabaseTransactions;

    // Organization lives on the 'crm' connection (Guild\BaseModel) — without declaring it here,
    // DatabaseTransactions only rolls back 'mysql' and these rows commit for real, leaking into
    // (and breaking) unrelated tests later in the same run. See tests/CLAUDE.md.
    protected array $connectionsToTransact = ['mysql', 'crm'];

    public function test_matches_a_vendor_despite_completely_different_legal_suffix_formatting(): void
    {
        // Reproduces the real NZXT case: the Organization is named the way Acumatica stores it
        // (legal form as a prefix), the invoice/spreadsheet spells it the readable way.
        $this->seedOrganization('GmbH-PENNER + PARTNER GBR');

        $result = OrganizationVendorMatcherService::match(
            app(Apps::class),
            $this->currentCompany(),
            'Penner + Partner WP StB mbB',
        );

        $this->assertTrue($result->isMatched());
        $this->assertSame('GmbH-PENNER + PARTNER GBR', $result->organization->name);
    }

    public function test_matches_an_exact_name_with_full_confidence(): void
    {
        $this->seedOrganization('Vendor Matcher Test Corp');

        $result = OrganizationVendorMatcherService::match(
            app(Apps::class),
            $this->currentCompany(),
            'Vendor Matcher Test Corp',
        );

        $this->assertTrue($result->isMatched());
        $this->assertSame('Vendor Matcher Test Corp', $result->organization->name);
    }

    public function test_returns_no_match_for_an_unrelated_name(): void
    {
        $this->seedOrganization('Vendor Matcher Test Corp');

        $result = OrganizationVendorMatcherService::match(
            app(Apps::class),
            $this->currentCompany(),
            'Totally Unrelated Vendor',
        );

        $this->assertFalse($result->isMatched());
        $this->assertSame([], $result->candidates);
    }

    public function test_returns_ambiguous_candidates_instead_of_guessing_when_two_vendors_tie(): void
    {
        // Both normalize down to the identical token set {alpha, logistics} — a genuine tie, so
        // the matcher must refuse to auto-pick rather than arbitrarily favor query order.
        $this->seedOrganization('Alpha Vendor Matcher Logistics GmbH');
        $this->seedOrganization('Alpha Vendor Matcher Logistics AG');

        $result = OrganizationVendorMatcherService::match(
            app(Apps::class),
            $this->currentCompany(),
            'Alpha Vendor Matcher Logistics',
        );

        $this->assertFalse($result->isMatched());
        $this->assertCount(2, $result->candidates);
    }

    private function seedOrganization(string $name): Organization
    {
        $app = app(Apps::class);
        $user = auth()->user();

        return Organization::create([
            'apps_id' => $app->getId(),
            'companies_id' => $this->currentCompany()->getId(),
            'users_id' => $user->getId(),
            'name' => $name,
            'address' => '',
            'total_employees' => 0,
        ]);
    }

    private function currentCompany()
    {
        return auth()->user()->getCurrentCompany();
    }
}
