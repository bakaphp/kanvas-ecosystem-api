<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use Kanvas\Inventory\Recommendations\Services\ProductDiscoverySettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\ManagesAppSettings;

#[Group('serial')]
class ProductDiscoverySettingsTest extends TestCase
{
    use DatabaseTransactions;
    use ManagesAppSettings;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'inventory', 'social'];

    /**
     * @return array<string, array{0: ?bool, 1: ?bool, 2: ?bool, 3: string}>
     */
    public static function precedenceMatrix(): array
    {
        return [
            'company true, app true' => [true, true, true, 'company'],
            'company true, app false' => [true, false, true, 'company'],
            'company true, app unset' => [true, null, true, 'company'],
            'company false, app true' => [false, true, false, 'company'],
            'company false, app false' => [false, false, false, 'company'],
            'company false, app unset' => [false, null, false, 'company'],
            'company unset, app true' => [null, true, true, 'app'],
            'company unset, app false' => [null, false, false, 'app'],
            'company unset, app unset' => [null, null, null, 'default'],
        ];
    }

    #[DataProvider('precedenceMatrix')]
    public function testEnrichmentFlagResolvesCompanyThenAppThenDefault(
        ?bool $company,
        ?bool $app,
        ?bool $expected,
        string $source,
    ): void {
        $settings = $this->settingsFor(ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED, $company, $app);

        $this->assertSame($expected, $settings->enrichmentEnabled());
        $this->assertSame($expected === true, $settings->enrichmentExplicitlyOn());
        $this->assertSame($expected === false, $settings->enrichmentExplicitlyOff());
        $this->assertSame($source, $settings->source(ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED->value));
    }

    #[DataProvider('precedenceMatrix')]
    public function testDiscoveryFlagResolvesCompanyThenAppThenDefault(
        ?bool $company,
        ?bool $app,
        ?bool $expected,
        string $source,
    ): void {
        $settings = $this->settingsFor(ConfigurationEnum::PRODUCT_DISCOVERY_ENABLED, $company, $app);

        $this->assertSame($expected, $settings->discoveryEnabled());
        $this->assertSame($expected === false, $settings->discoveryExplicitlyOff());
        $this->assertSame($source, $settings->source(ConfigurationEnum::PRODUCT_DISCOVERY_ENABLED->value));
    }

    public function testTheTwoFlagsDoNotShareState(): void
    {
        $settings = $this->settingsFor(ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED, true, null);

        $this->assertTrue($settings->enrichmentExplicitlyOn());
        $this->assertNull($settings->discoveryEnabled());
        $this->assertFalse($settings->discoveryExplicitlyOff());
        $this->assertSame('default', $settings->source(ConfigurationEnum::PRODUCT_DISCOVERY_ENABLED->value));
    }

    /**
     * @return array<string, array{0: mixed, 1: ?bool}>
     */
    public static function storedShapes(): array
    {
        return [
            'string 1' => ['1', true],
            'string 0' => ['0', false],
            'string true' => ['true', true],
            'string false' => ['false', false],
            'string TRUE with padding' => [' TRUE ', true],
            'string yes' => ['yes', true],
            'string off' => ['off', false],
            'int 1' => [1, true],
            'int 0' => [0, false],
            'bool true' => [true, true],
            'bool false' => [false, false],
            'empty string reads as unset' => ['', null],
            'junk reads as unset' => ['maybe', null],
            'out of range int reads as unset' => [7, null],
        ];
    }

    #[DataProvider('storedShapes')]
    public function testCompanyValueIsNormalizedFromWhateverTheSettingsUiStored(mixed $stored, ?bool $expected): void
    {
        $company = Companies::factory()->create();
        $company->set(ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED->value, $stored);

        try {
            $this->assertSame(
                $expected,
                ProductDiscoverySettings::for(app(Apps::class), $company)->enrichmentEnabled(),
            );
        } finally {
            $company->deleteAllSettings();
        }
    }

    public function testAnUnreadableCompanyValueFallsThroughToTheApp(): void
    {
        $app = $this->setAppSettings([ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED->value => true]);
        $company = Companies::factory()->create();
        $company->set(ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED->value, 'maybe');

        try {
            $settings = ProductDiscoverySettings::for($app, $company);

            $this->assertTrue($settings->enrichmentExplicitlyOn());
            $this->assertSame('app', $settings->source(ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED->value));
        } finally {
            $company->deleteAllSettings();
        }
    }

    public function testWithoutACompanyOnlyTheAppIsConsulted(): void
    {
        $app = $this->setAppSettings([ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED->value => false]);

        $settings = ProductDiscoverySettings::for($app, null);

        $this->assertTrue($settings->enrichmentExplicitlyOff());
        $this->assertSame('app', $settings->source(ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED->value));
    }

    public function testSourceRejectsAKeyThatIsNotAFlag(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProductDiscoverySettings::for(app(Apps::class), null)->source(ConfigurationEnum::CACHE_TTL->value);
    }

    private function settingsFor(ConfigurationEnum $flag, ?bool $company, ?bool $app): ProductDiscoverySettings
    {
        $this->rememberAppSettings($flag->value);
        $appModel = app(Apps::class);
        $appModel->del($flag->value);

        if ($app !== null) {
            $this->setAppSettings([$flag->value => $app]);
        }

        $companyModel = Companies::factory()->create();

        if ($company !== null) {
            $companyModel->set($flag->value, $company);
            $this->beforeApplicationDestroyed(fn () => $companyModel->deleteAllSettings());
        }

        return ProductDiscoverySettings::for($appModel, $companyModel);
    }
}
