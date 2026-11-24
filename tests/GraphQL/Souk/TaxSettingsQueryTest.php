<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Taxes\Enums\ConfigurationEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('serial')]
final class TaxSettingsQueryTest extends TestCase
{
    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
    }

    protected function tearDown(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, 0);
        $this->kanvasApp->set(ConfigurationEnum::TAX_FAIL_OPEN->value, 0);

        parent::tearDown();
    }

    public function testReflectsTheAppTaxSettings(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, 1);
        $this->kanvasApp->set(ConfigurationEnum::TAX_FAIL_OPEN->value, 0);

        $this->graphQL('
            query {
                taxSettings { enabled fail_open }
            }
        ')->assertJson(['data' => ['taxSettings' => ['enabled' => true, 'fail_open' => false]]]);
    }

    public function testMissingSettingsReadAsOff(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, 0);

        $this->graphQL('
            query {
                taxSettings { enabled fail_open }
            }
        ')->assertJson(['data' => ['taxSettings' => ['enabled' => false, 'fail_open' => false]]]);
    }
}
