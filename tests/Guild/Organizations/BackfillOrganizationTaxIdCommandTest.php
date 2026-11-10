<?php

declare(strict_types=1);

namespace Tests\Guild\Organizations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Enums\TaxIdTypeEnum;
use Kanvas\Guild\Organizations\Models\Organization;
use Tests\TestCase;

/**
 * Each test runs on a fresh company and passes --company, so the command never touches the shared rows
 * (custom fields commit on the ecosystem connection regardless of the transaction).
 */
final class BackfillOrganizationTaxIdCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kanvasApp = app(Apps::class);
    }

    public function test_copies_with_precedence_normalization_and_type_and_keeps_the_custom_fields(): void
    {
        $company = $this->company('DO');
        $both = $this->organization($company, ['rnc' => '101000001', 'tax_id' => '131-79432-1']);
        $rncOnly = $this->organization($company, ['rnc' => '001-1234567-8']);
        $einOnly = $this->organization($company, ['ein' => '12-3456789']);
        $placeholder = $this->organization($company, ['rnc' => '000000000']);
        $lettered = $this->organization($company, ['tax_id' => ' B12345678 ']);

        $this->artisan('guild:backfill-organization-tax-id', ['--company' => $company->getId()])
            ->expectsOutputToContain('conflicting tax ids')
            ->expectsOutputToContain('filled: 4 (type left null, ambiguous: 1), already set: 0, placeholder/blank skipped: 1, conflicts: 1')
            ->assertSuccessful();

        $this->assertSame('131794321', $both->refresh()->tax_id, 'tax_id wins over rnc.');
        $this->assertSame(TaxIdTypeEnum::DO_RNC, $both->tax_id_type);
        $this->assertSame('00112345678', $rncOnly->refresh()->tax_id);
        $this->assertSame(TaxIdTypeEnum::DO_CEDULA, $rncOnly->tax_id_type);
        $this->assertSame('123456789', $einOnly->refresh()->tax_id);
        $this->assertSame(TaxIdTypeEnum::US_EIN, $einOnly->tax_id_type, 'The key names the country.');
        $this->assertNull($placeholder->refresh()->tax_id, 'An all-zero placeholder is not an id.');
        $this->assertSame('B12345678', $lettered->refresh()->tax_id);
        $this->assertNull($lettered->tax_id_type, 'Not a Dominican shape: left for a person to type.');
        $this->assertSame('101000001', (string) $both->get('rnc'), 'The custom field stays.');
    }

    public function test_dry_run_writes_nothing_and_a_second_run_changes_nothing(): void
    {
        $company = $this->company(null);
        $organization = $this->organization($company, ['rnc' => '131794321']);

        $this->artisan('guild:backfill-organization-tax-id', ['--company' => $company->getId(), '--dry-run' => true])
            ->expectsOutputToContain('[dry-run] filled: 1')
            ->assertSuccessful();
        $this->assertNull($organization->refresh()->tax_id);

        $this->artisan('guild:backfill-organization-tax-id', ['--company' => $company->getId()])->assertSuccessful();
        $this->assertSame('131794321', $organization->refresh()->tax_id);
        $this->assertSame(TaxIdTypeEnum::DO_RNC, $organization->tax_id_type, 'A key named rnc is Dominican even with no company country.');

        $organization->set('rnc', '101000001');
        $this->artisan('guild:backfill-organization-tax-id', ['--company' => $company->getId()])
            ->expectsOutputToContain('filled: 0 (type left null, ambiguous: 0), already set: 1')
            ->assertSuccessful();
        $this->assertSame('131794321', $organization->refresh()->tax_id, 'A filled column is never overwritten.');
    }

    private function company(?string $countryCode): Companies
    {
        return Companies::factory()->create([
            'users_id' => static::$cachedUser->getId(),
            'country_code' => $countryCode,
        ]);
    }

    /**
     * @param array<string, string> $customFields
     */
    private function organization(Companies $company, array $customFields): Organization
    {
        $organization = Organization::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $company->getId(),
            'users_id' => static::$cachedUser->getId(),
            'name' => 'Backfill Org ' . uniqid(),
            'address' => '',
            'total_employees' => 0,
        ]);

        foreach ($customFields as $name => $value) {
            $organization->set($name, $value);
        }

        return $organization;
    }
}
