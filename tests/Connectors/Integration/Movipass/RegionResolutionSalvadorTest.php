<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Inventory\Regions\Enums\ConfigurationEnum;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Regions\Services\RegionResolutionService;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('serial')]
final class RegionResolutionSalvadorTest extends TestCase
{
    use DatabaseTransactions;

    private const string SALVADOR_IP = '190.5.6.7';
    private const string OTHER_COUNTRY_IP = '190.5.6.8';

    private Apps $appInstance;
    private mixed $originalCountryMap;

    protected function connectionsToTransact(): array
    {
        return [null, 'inventory'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Movipass region tests are skipped in CI');
        }

        $this->appInstance = app(Apps::class);
        $this->originalCountryMap = $this->appInstance->get(ConfigurationEnum::REGION_COUNTRY_MAP->value);
    }

    protected function tearDown(): void
    {
        if (isset($this->appInstance)) {
            $this->originalCountryMap === null
                ? $this->appInstance->del(ConfigurationEnum::REGION_COUNTRY_MAP->value)
                : $this->appInstance->set(ConfigurationEnum::REGION_COUNTRY_MAP->value, $this->originalCountryMap);
            Cache::forget('ipinfo:country:' . self::SALVADOR_IP);
            Cache::forget('ipinfo:country:' . self::OTHER_COUNTRY_IP);
        }

        parent::tearDown();
    }

    public function testAnIpThatResolvesToElSalvadorBindsTheSalvadorRegion(): void
    {
        $salvador = $this->salvadorRegion();
        $this->mapCountries(['SV' => $salvador->uuid]);
        Cache::put('ipinfo:country:' . self::SALVADOR_IP, 'SV', 86400);

        $resolved = new RegionResolutionService($this->appInstance)->resolve($this->requestFrom(self::SALVADOR_IP), null);

        $this->assertNotNull($resolved);
        $this->assertSame($salvador->getId(), $resolved->getId());
        $this->assertSame('el-salvador', $resolved->slug);
    }

    public function testAnIpFromAnotherCountryDoesNotBindTheSalvadorRegion(): void
    {
        $salvador = $this->salvadorRegion();
        $this->mapCountries(['SV' => $salvador->uuid]);
        Cache::put('ipinfo:country:' . self::OTHER_COUNTRY_IP, 'GT', 86400);

        $resolved = new RegionResolutionService($this->appInstance)->resolve($this->requestFrom(self::OTHER_COUNTRY_IP), null);

        $this->assertNull($resolved);
    }

    private function salvadorRegion(): Regions
    {
        $region = Regions::query()
            ->where('apps_id', $this->appInstance->getId())
            ->where('companies_id', 0)
            ->where('slug', 'el-salvador')
            ->first();

        return $region ?? Regions::create([
            'apps_id' => $this->appInstance->getId(),
            'companies_id' => 0,
            'users_id' => 0,
            'name' => 'El Salvador',
            'slug' => 'el-salvador',
            'short_slug' => 'SV',
            'currency_id' => Currencies::getByCode('USD')->getId(),
            'is_default' => 0,
        ]);
    }

    private function mapCountries(array $map): void
    {
        $this->appInstance->set(ConfigurationEnum::REGION_COUNTRY_MAP->value, json_encode($map));
    }

    private function requestFrom(string $ip): Request
    {
        return Request::create('/test', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);
    }
}
