<?php

declare(strict_types=1);

namespace Tests\Guild\Organizations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Actions\ResolveOrCreateOrganizationFromVendorPayloadAction;
use Kanvas\Guild\Organizations\Models\Organization;
use Tests\TestCase;

/**
 * Covers the match-precedence ladder used by ProposeBillFromPdfAction / ProposeExpenseFromPdfAction
 * to link inbound PDF extractions to the right Guild Organization (or create one when no match).
 *
 *   1. email exact match (organizations.email column, case-insensitive)
 *   2. exact name (case-insensitive)
 *   3. fuzzy name (≥ 90% similar_text)
 *   4. no match → create
 */
class ResolveOrCreateOrganizationFromVendorPayloadActionTest extends TestCase
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

    public function test_matches_by_email_case_insensitive(): void
    {
        $existing = $this->seedOrganization('Office Supply Co', email: 'AP@OfficeSupply.test');

        $resolved = new ResolveOrCreateOrganizationFromVendorPayloadAction(
            app: $this->kanvasApp,
            company: $this->company,
            user: static::$cachedUser,
            payload: [
                'vendor_name' => 'Some New Name',         // wouldn't match by name
                'vendor_email' => 'ap@officesupply.test', // ← match by lowered email
            ],
        )->execute();

        $this->assertNotNull($resolved);
        $this->assertSame((int) $existing->id, (int) $resolved->id, 'email match should win over different name.');
    }

    public function test_matches_by_exact_name_case_insensitive(): void
    {
        // Unique-suffix name so we don't collide with leftover rows from prior live runs.
        $suffix = uniqid('xn');
        $existing = $this->seedOrganization("ACME Corp {$suffix}");

        $resolved = new ResolveOrCreateOrganizationFromVendorPayloadAction(
            app: $this->kanvasApp,
            company: $this->company,
            user: static::$cachedUser,
            payload: [
                'vendor_name' => "  acme corp {$suffix} ",  // different case + whitespace
            ],
        )->execute();

        $this->assertNotNull($resolved);
        $this->assertSame((int) $existing->id, (int) $resolved->id);
    }

    public function test_matches_by_fuzzy_name_above_threshold(): void
    {
        $suffix = uniqid('xf');
        $existing = $this->seedOrganization("Mercury Insurance Group {$suffix}");

        // similar_text adding the same unique suffix to both keeps ratio > 90%
        $resolved = new ResolveOrCreateOrganizationFromVendorPayloadAction(
            app: $this->kanvasApp,
            company: $this->company,
            user: static::$cachedUser,
            payload: [
                'vendor_name' => "Mercury Insurance Grp {$suffix}",
            ],
        )->execute();

        $this->assertNotNull($resolved);
        $this->assertSame(
            (int) $existing->id,
            (int) $resolved->id,
            'fuzzy match should reuse near-duplicate org rather than creating a new one.',
        );
    }

    public function test_no_match_creates_new_organization_with_payload_data(): void
    {
        $uniqueEmail = 'unique-' . uniqid() . '@example.test';
        $resolved = new ResolveOrCreateOrganizationFromVendorPayloadAction(
            app: $this->kanvasApp,
            company: $this->company,
            user: static::$cachedUser,
            payload: [
                'vendor_name' => 'Unique Vendor ' . uniqid(),
                'vendor_email' => $uniqueEmail,
            ],
        )->execute();

        $this->assertNotNull($resolved);
        $this->assertStringStartsWith('Unique Vendor', (string) $resolved->name);
        $this->assertSame($uniqueEmail, $resolved->email, 'New org should carry the extracted email.');
        $this->assertSame($this->kanvasApp->getId(), (int) $resolved->apps_id);
        $this->assertSame($this->company->getId(), (int) $resolved->companies_id);
        $this->assertFalse((bool) $resolved->is_deleted);
    }

    public function test_returns_null_when_payload_has_no_vendor_name_and_no_match(): void
    {
        $resolved = new ResolveOrCreateOrganizationFromVendorPayloadAction(
            app: $this->kanvasApp,
            company: $this->company,
            user: static::$cachedUser,
            payload: ['vendor_name' => '', 'vendor_email' => null],
        )->execute();

        $this->assertNull($resolved, 'No name + no match → null (caller decides what to do, no Org created).');
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
}
