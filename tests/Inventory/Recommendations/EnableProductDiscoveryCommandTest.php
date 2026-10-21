<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\ManagesAppSettings;

#[Group('serial')]
class EnableProductDiscoveryCommandTest extends TestCase
{
    use ManagesAppSettings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberAppSettings(
            'products_search_engine',
            ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED->value,
            ConfigurationEnum::PRODUCT_DISCOVERY_ENABLED->value,
        );
        app(Apps::class)->set('products_search_engine', 'database');
    }

    public function testTurnsDiscoveryOnForTheAppAndLeavesEnrichmentAlone(): void
    {
        app(Apps::class)->del(ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED->value);

        $this->artisan('kanvas-inventory:enable-product-discovery', [
            'app_id' => app(Apps::class)->getId(),
            '--discovery' => 'on',
        ])
            ->expectsOutputToContain('Discovery: on')
            ->assertExitCode(0);

        $this->assertTrue((bool) app(Apps::class)->get(ConfigurationEnum::PRODUCT_DISCOVERY_ENABLED->value));
        $this->assertNull(app(Apps::class)->get(ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED->value));
    }

    public function testRejectsAValueThatIsNotOnOrOff(): void
    {
        $this->artisan('kanvas-inventory:enable-product-discovery', [
            'app_id' => app(Apps::class)->getId(),
            '--enrichment' => 'maybe',
        ])
            ->expectsOutputToContain('takes "on" or "off"')
            ->assertExitCode(1);
    }
}
