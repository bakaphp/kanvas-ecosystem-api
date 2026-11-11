<?php

declare(strict_types=1);

namespace Tests\Guild\Organizations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Actions\ResolveOrCreateOrganizationFromVendorPayloadAction;
use Kanvas\Guild\Organizations\Enums\TaxIdTypeEnum;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Bills\Services\VendorByTaxIdResolverService;
use Tests\TestCase;

final class OrganizationTaxIdTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kanvasApp = app(Apps::class);
    }

    public function test_the_column_is_normalized_and_read_by_both_party_interfaces(): void
    {
        $organization = $this->organization($this->company('DO'), ['tax_id' => ' 131-79432-1 ']);

        $this->assertSame('131794321', $organization->refresh()->tax_id);
        $this->assertSame('131794321', $organization->getBillableTaxId());
        $this->assertSame('131794321', $organization->getPayeeTaxId());
    }

    public function test_a_tax_id_left_only_in_a_custom_field_is_not_read_until_backfilled(): void
    {
        $organization = $this->organization($this->company('DO'));
        $organization->set('rnc', '131794321');

        $this->assertNull($organization->refresh()->getBillableTaxId());
        $this->assertNull($organization->getPayeeTaxId());
    }

    public function test_the_type_is_inferred_from_the_company_country(): void
    {
        $dominican = $this->organization($this->company('DO'), ['tax_id' => '00112345678']);
        $this->assertSame(TaxIdTypeEnum::DO_CEDULA, $dominican->refresh()->tax_id_type);
        $this->assertSame('Cédula', $dominican->taxIdLabel());

        $unknownCountry = $this->organization($this->company(null), ['tax_id' => '131794321']);
        $this->assertNull($unknownCountry->refresh()->tax_id_type);
        $this->assertSame('Tax ID', $unknownCountry->taxIdLabel());
    }

    public function test_changing_the_tax_id_re_infers_the_type_and_clearing_it_clears_the_type(): void
    {
        $organization = $this->organization($this->company('DO'), ['tax_id' => '131794321']);
        $this->assertSame(TaxIdTypeEnum::DO_RNC, $organization->tax_id_type);

        $organization->tax_id = '001-1234567-8';
        $organization->saveOrFail();
        $this->assertSame(TaxIdTypeEnum::DO_CEDULA, $organization->refresh()->tax_id_type);

        $organization->tax_id = null;
        $organization->saveOrFail();
        $this->assertNull($organization->refresh()->tax_id_type);
    }

    public function test_an_explicit_type_that_does_not_fit_the_id_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('is not a valid RNC');

        $this->organization($this->company('DO'), [
            'tax_id' => '00112345678',
            'tax_id_type' => TaxIdTypeEnum::DO_RNC,
        ]);
    }

    public function test_an_explicit_foreign_type_is_kept_on_a_dominican_company(): void
    {
        $organization = $this->organization($this->company('DO'), [
            'tax_id' => '12-3456789',
            'tax_id_type' => TaxIdTypeEnum::US_EIN,
        ]);

        $this->assertSame('123456789', $organization->refresh()->tax_id);
        $this->assertSame(TaxIdTypeEnum::US_EIN, $organization->tax_id_type);
    }

    public function test_the_vendor_resolver_finds_by_the_column_inside_the_tenant_only(): void
    {
        $company = $this->company('DO');
        $vendor = $this->organization($company, ['tax_id' => '131794321']);
        $this->organization($this->company('DO'), ['tax_id' => '131794321']);

        $found = new VendorByTaxIdResolverService()->find($this->kanvasApp, $company, '131-79432-1');

        $this->assertSame((int) $vendor->getId(), (int) $found?->getId());
        $this->assertNull(new VendorByTaxIdResolverService()->find($this->kanvasApp, $company, '101000001'));
    }

    public function test_resolve_or_create_writes_the_column_on_a_name_match_without_one(): void
    {
        $company = $this->company('DO');
        $vendor = $this->organization($company, ['name' => 'Suplidora Sin RNC SRL']);

        $resolved = new VendorByTaxIdResolverService()->resolveOrCreate(
            $this->kanvasApp,
            $company,
            static::$cachedUser,
            '131794321',
            ['vendor_name' => 'Suplidora Sin RNC SRL'],
        );

        $this->assertSame((int) $vendor->getId(), (int) $resolved?->getId());
        $vendor->refresh();
        $this->assertSame('131794321', $vendor->tax_id);
        $this->assertSame(TaxIdTypeEnum::DO_RNC, $vendor->tax_id_type);
        $this->assertNull($vendor->get('rnc'), 'No custom field is written.');
    }

    public function test_resolve_or_create_refuses_a_name_match_carrying_another_tax_id(): void
    {
        $company = $this->company('DO');
        $this->organization($company, ['name' => 'Otra Suplidora SRL', 'tax_id' => '101000001']);

        $resolved = new VendorByTaxIdResolverService()->resolveOrCreate(
            $this->kanvasApp,
            $company,
            static::$cachedUser,
            '131794321',
            ['vendor_name' => 'Otra Suplidora SRL'],
        );

        $this->assertNull($resolved);
    }

    public function test_resolve_or_create_creates_the_vendor_with_the_given_type(): void
    {
        $company = $this->company(null);

        $resolved = new VendorByTaxIdResolverService()->resolveOrCreate(
            $this->kanvasApp,
            $company,
            static::$cachedUser,
            '00112345678',
            ['vendor_name' => 'Proveedor Nuevo ' . uniqid(), 'vendor_tax_id' => '00112345678'],
            taxIdType: TaxIdTypeEnum::DO_CEDULA,
        );

        $this->assertNotNull($resolved);
        $this->assertSame('00112345678', $resolved->refresh()->tax_id);
        $this->assertSame(TaxIdTypeEnum::DO_CEDULA, $resolved->tax_id_type);
    }

    public function test_the_vendor_payload_action_matches_the_tax_id_before_the_name_and_creates_with_it(): void
    {
        $company = $this->company('DO');
        $vendor = $this->organization($company, ['tax_id' => '131794321']);

        $matched = new ResolveOrCreateOrganizationFromVendorPayloadAction(
            app: $this->kanvasApp,
            company: $company,
            user: static::$cachedUser,
            payload: ['vendor_name' => 'A Different Trading Name', 'vendor_tax_id' => '131-79432-1'],
        )->execute();
        $this->assertSame((int) $vendor->getId(), (int) $matched?->getId());

        $created = new ResolveOrCreateOrganizationFromVendorPayloadAction(
            app: $this->kanvasApp,
            company: $company,
            user: static::$cachedUser,
            payload: ['vendor_name' => 'Brand New Vendor ' . uniqid(), 'vendor_tax_id' => '101-00000-1'],
        )->execute();
        $this->assertSame('101000001', $created?->refresh()->tax_id);
        $this->assertSame(TaxIdTypeEnum::DO_RNC, $created->tax_id_type);
    }

    private function company(?string $countryCode): Companies
    {
        return Companies::factory()->create([
            'users_id' => static::$cachedUser->getId(),
            'country_code' => $countryCode,
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function organization(Companies $company, array $attributes = []): Organization
    {
        return Organization::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $company->getId(),
            'users_id' => static::$cachedUser->getId(),
            'name' => 'Org ' . uniqid(),
            'address' => '',
            'total_employees' => 0,
            ...$attributes,
        ]);
    }
}
