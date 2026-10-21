<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;
use Tests\Traits\ManagesAppSettings;

#[Group('serial')]
class ProductDiscoverySetupTest extends TestCase
{
    use InventoryCases;
    use ManagesAppSettings;

    private const string MUTATION = '
        mutation($input: EnableProductDiscoveryInput!) {
            enableProductDiscovery(input: $input) {
                scope
                engine
                enrichment
                discovery
                backfill_queued
                index { status message }
            }
        }
    ';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberAppSettings(
            'products_search_engine',
            ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED->value,
            ConfigurationEnum::PRODUCT_DISCOVERY_ENABLED->value,
        );
        app(Apps::class)->set('products_search_engine', 'database');
        app(Apps::class)->del(ConfigurationEnum::PRODUCT_DISCOVERY_ENABLED->value);
    }

    public function testItRequiresTheAppKey(): void
    {
        $response = $this->graphQL(self::MUTATION, ['input' => ['scope' => 'APP', 'discovery' => true]]);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertNull($response->json('data.enableProductDiscovery'));
        $this->assertNull(app(Apps::class)->get(ConfigurationEnum::PRODUCT_DISCOVERY_ENABLED->value));
    }

    public function testAppScopeSetsTheAppFlagAndReportsTheDetectedEngine(): void
    {
        $response = $this->graphQL(
            self::MUTATION,
            ['input' => ['scope' => 'APP', 'discovery' => true]],
            [],
            $this->appKeyHeader(),
        );

        $data = $this->graphQLData($response, 'enableProductDiscovery');

        $this->assertSame('APP', $data['scope']);
        $this->assertSame('sql', $data['engine']);
        $this->assertTrue($data['discovery']);
        $this->assertNull($data['enrichment']);
        $this->assertSame('skipped', $data['index']['status']);
        $this->assertTrue((bool) app(Apps::class)->get(ConfigurationEnum::PRODUCT_DISCOVERY_ENABLED->value));
    }

    public function testCompanyScopeNeedsACompany(): void
    {
        $response = $this->graphQL(
            self::MUTATION,
            ['input' => ['scope' => 'COMPANY', 'discovery' => true]],
            [],
            $this->appKeyHeader(),
        );

        $this->assertStringContainsString('company_id is required', (string) $response->json('errors.0.message'));
    }

    public function testAppScopeRejectsACompany(): void
    {
        $response = $this->graphQL(
            self::MUTATION,
            ['input' => ['scope' => 'APP', 'company_id' => 1, 'discovery' => true]],
            [],
            $this->appKeyHeader(),
        );

        $this->assertStringContainsString('only valid when scope is COMPANY', (string) $response->json('errors.0.message'));
    }

    public function testACompanyOutsideTheAppIsRefused(): void
    {
        $response = $this->graphQL(
            self::MUTATION,
            ['input' => ['scope' => 'COMPANY', 'company_id' => 999999999, 'discovery' => true]],
            [],
            $this->appKeyHeader(),
        );

        $this->assertStringContainsString('No company 999999999', (string) $response->json('errors.0.message'));
    }

    public function testCompanyScopeWritesTheCompanyFlagOnly(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $company->del(ConfigurationEnum::PRODUCT_DISCOVERY_ENABLED->value);

        try {
            $response = $this->graphQL(
                self::MUTATION,
                ['input' => ['scope' => 'COMPANY', 'company_id' => $company->getId(), 'discovery' => false]],
                [],
                $this->appKeyHeader(),
            );

            $data = $this->graphQLData($response, 'enableProductDiscovery');

            $this->assertSame('COMPANY', $data['scope']);
            $this->assertFalse($data['discovery']);
            $this->assertFalse((bool) $company->get(ConfigurationEnum::PRODUCT_DISCOVERY_ENABLED->value));
            $this->assertNull(app(Apps::class)->get(ConfigurationEnum::PRODUCT_DISCOVERY_ENABLED->value));
        } finally {
            $company->del(ConfigurationEnum::PRODUCT_DISCOVERY_ENABLED->value);
        }
    }

    /**
     * @return array<string, string>
     */
    private function appKeyHeader(): array
    {
        return [AppEnums::KANVAS_APP_KEY_HEADER->getValue() => app(Apps::class)->keys()->first()->client_secret_id];
    }
}
