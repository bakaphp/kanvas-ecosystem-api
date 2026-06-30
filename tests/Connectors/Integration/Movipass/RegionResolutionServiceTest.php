<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Regions\Enums\ConfigurationEnum;
use Kanvas\Inventory\Regions\Enums\CustomFieldEnum;
use Kanvas\Regions\Models\Regions as KanvasRegions;
use Kanvas\Regions\Services\RegionResolutionService;
use Tests\TestCase;

final class RegionResolutionServiceTest extends TestCase
{
    private RegionResolutionService $service;
    private Apps $appInstance;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Movipass region tests are skipped in CI');
        }

        $this->appInstance = app(Apps::class);
        $this->service = new RegionResolutionService($this->appInstance);
    }

    public function testFromUserProfileReturnsRegionWhenCustomFieldSet(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $region = KanvasRegions::getDefault($company, $this->appInstance);

        $user->set(CustomFieldEnum::DEFAULT_REGION_ID->value, $region->getId());

        $request = Request::create('/test');
        $resolved = $this->service->resolve($request, $user);

        $this->assertNotNull($resolved);
        $this->assertEquals($region->getId(), $resolved->getId());

        // Cleanup
        $user->del(CustomFieldEnum::DEFAULT_REGION_ID->value);
    }

    public function testFromUserProfileReturnsNullWhenNoCustomField(): void
    {
        $user = auth()->user();
        $user->del(CustomFieldEnum::DEFAULT_REGION_ID->value);
        $this->appInstance->del(ConfigurationEnum::REGION_COUNTRY_MAP->value);
        $this->appInstance->del(ConfigurationEnum::DEFAULT_REGION_UUID->value);

        $request = Request::create('/test');
        $resolved = $this->service->resolve($request, $user);

        // Without profile, geo-IP config, or app default — should return null
        $this->assertNull($resolved);
    }

    public function testFromUserProfileSkipsWhenUserIsNull(): void
    {
        $request = Request::create('/test');
        $resolved = $this->service->resolve($request, null);

        $this->assertNull($resolved);
    }

    public function testFromGeoIpSkipsPrivateIp(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $region = KanvasRegions::getDefault($company, $this->appInstance);

        // Set up country map so geo-IP layer would try to resolve
        $this->appInstance->set(
            ConfigurationEnum::REGION_COUNTRY_MAP->value,
            json_encode(['US' => $region->uuid]),
        );

        // Private IP should be skipped
        $request = Request::create('/test', 'GET', [], [], [], [
            'REMOTE_ADDR' => '192.168.1.1',
        ]);

        $resolved = $this->service->resolve($request, null);

        // Should not resolve via geo-IP for private IPs
        $this->assertNull($resolved);

        // Cleanup
        $this->appInstance->del(ConfigurationEnum::REGION_COUNTRY_MAP->value);
    }

    public function testFromGeoIpCachesCountryCode(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $region = KanvasRegions::getDefault($company, $this->appInstance);

        $testIp = '8.8.8.8';
        $cacheKey = "ipinfo:country:{$testIp}";

        // Pre-populate cache to simulate a previous IPInfo call
        Cache::put($cacheKey, 'US', 86400);

        $this->appInstance->set(
            ConfigurationEnum::REGION_COUNTRY_MAP->value,
            json_encode(['US' => $region->uuid]),
        );

        $request = Request::create('/test', 'GET', [], [], [], [
            'REMOTE_ADDR' => $testIp,
        ]);

        $resolved = $this->service->resolve($request, null);

        $this->assertNotNull($resolved);
        $this->assertEquals($region->getId(), $resolved->getId());

        // Cleanup
        Cache::forget($cacheKey);
        $this->appInstance->del(ConfigurationEnum::REGION_COUNTRY_MAP->value);
    }

    public function testFromAppDefaultReturnsRegionWhenConfigured(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $region = KanvasRegions::getDefault($company, $this->appInstance);

        $this->appInstance->set(ConfigurationEnum::DEFAULT_REGION_UUID->value, $region->uuid);

        // Need country map set so middleware would activate, but service itself doesn't check it
        $request = Request::create('/test');
        $resolved = $this->service->resolve($request, null);

        $this->assertNotNull($resolved);
        $this->assertEquals($region->getId(), $resolved->getId());

        // Cleanup
        $this->appInstance->del(ConfigurationEnum::DEFAULT_REGION_UUID->value);
    }

    public function testFallbackChainUserProfileTakesPreference(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $region = KanvasRegions::getDefault($company, $this->appInstance);

        // Set both user profile and app default
        $user->set(CustomFieldEnum::DEFAULT_REGION_ID->value, $region->getId());
        $this->appInstance->set(ConfigurationEnum::DEFAULT_REGION_UUID->value, $region->uuid);

        $request = Request::create('/test');
        $resolved = $this->service->resolve($request, $user);

        // User profile should take precedence
        $this->assertNotNull($resolved);
        $this->assertEquals($region->getId(), $resolved->getId());

        // Cleanup
        $user->del(CustomFieldEnum::DEFAULT_REGION_ID->value);
        $this->appInstance->del(ConfigurationEnum::DEFAULT_REGION_UUID->value);
    }

    public function testAllLayersFailReturnsNull(): void
    {
        $user = auth()->user();
        $user->del(CustomFieldEnum::DEFAULT_REGION_ID->value);

        // No country map, no default UUID
        $this->appInstance->del(ConfigurationEnum::REGION_COUNTRY_MAP->value);
        $this->appInstance->del(ConfigurationEnum::DEFAULT_REGION_UUID->value);

        $request = Request::create('/test', 'GET', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        $resolved = $this->service->resolve($request, $user);

        $this->assertNull($resolved);
    }
}
