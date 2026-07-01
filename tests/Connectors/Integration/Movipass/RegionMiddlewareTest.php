<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use App\Http\Middleware\RegionMiddleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Inventory\Regions\Enums\ConfigurationEnum;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Regions\Models\Regions as KanvasRegions;
use Tests\TestCase;

final class RegionMiddlewareTest extends TestCase
{
    private Apps $appInstance;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Movipass region tests are skipped in CI');
        }

        $this->appInstance = app(Apps::class);
        app()->forgetInstance(Regions::class);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance(Regions::class);
        $this->appInstance->del(ConfigurationEnum::REGION_COUNTRY_MAP->value);
        $this->appInstance->del(ConfigurationEnum::DEFAULT_REGION_UUID->value);

        parent::tearDown();
    }

    public function testInvalidRegionHeaderIsIgnoredAndRequestCompletes(): void
    {
        $this->appInstance->del(ConfigurationEnum::REGION_COUNTRY_MAP->value);
        $this->appInstance->del(ConfigurationEnum::DEFAULT_REGION_UUID->value);

        $request = Request::create('/test');
        $request->headers->set(
            (string) AppEnums::KANVAS_APP_REGION_HEADER->getValue(),
            'non-existent-region-uuid-0000'
        );

        $response = new RegionMiddleware()->handle($request, fn () => new Response('ok'));

        $this->assertEquals('ok', $response->getContent());
        $this->assertFalse(app()->bound(Regions::class));
    }

    public function testInvalidRegionHeaderFallsThroughToAppDefault(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $region = KanvasRegions::getDefault($company, $this->appInstance);

        // fallback guard needs a country map; default resolves via default_region_uuid
        $this->appInstance->set(ConfigurationEnum::REGION_COUNTRY_MAP->value, ['US' => $region->uuid]);
        $this->appInstance->set(ConfigurationEnum::DEFAULT_REGION_UUID->value, $region->uuid);

        $request = Request::create('/test');
        $request->headers->set(
            (string) AppEnums::KANVAS_APP_REGION_HEADER->getValue(),
            'non-existent-region-uuid-0000'
        );

        $response = new RegionMiddleware()->handle($request, fn () => new Response('ok'));

        $this->assertEquals('ok', $response->getContent());
        $this->assertTrue(app()->bound(Regions::class));
        $this->assertEquals($region->uuid, app(Regions::class)->uuid);
    }
}
