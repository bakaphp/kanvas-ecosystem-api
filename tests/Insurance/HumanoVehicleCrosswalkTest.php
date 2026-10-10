<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Humano\Providers\HumanoProvider;
use Kanvas\Connectors\Humano\Services\HumanoService;
use Kanvas\Insurance\Actions\BuildVehicleModelCrosswalkAction;
use Kanvas\Insurance\Actions\CreateInsurerAction;
use Kanvas\Insurance\DataTransferObject\CanonicalVehicle;
use Kanvas\Insurance\DataTransferObject\ExternalVehicleReference;
use Kanvas\Insurance\DataTransferObject\Insurer as InsurerData;
use Kanvas\Insurance\Jobs\BuildVehicleModelCrosswalkJob;
use Kanvas\Insurance\Models\InsuranceVehicleModelMap;
use Kanvas\Insurance\Models\Insurer;
use Kanvas\Insurance\Services\InsuranceVehicleModelResolver;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class HumanoVehicleCrosswalkTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'commerce'];

    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::flush();
        $this->calls = [];
    }

    private function rows(array $overrides = []): array
    {
        return array_replace_recursive([
            'brands' => [
                '1' => [['P_CD_DATO' => '40', 'P_DE_DATO' => 'HONDA'], ['P_CD_DATO' => '1', 'P_DE_DATO' => 'ACURA'], ['P_CD_DATO' => '2', 'P_DE_DATO' => 'TOYOTA']],
                '3' => [['P_CD_DATO' => '41', 'P_DE_DATO' => 'HONDA']],
                '4' => [['P_CD_DATO' => '40', 'P_DE_DATO' => 'HONDA']],
            ],
            'models' => [
                '1|40' => [['P_CD_DATO' => '1', 'P_DE_DATO' => 'ACCORD'], ['P_CD_DATO' => '2', 'P_DE_DATO' => 'CIVIC']],
                '1|2' => [['P_CD_DATO' => '5', 'P_DE_DATO' => 'COROLLA']],
                '3|41' => [['P_CD_DATO' => '8', 'P_DE_DATO' => 'CIVIC']],
                '4|40' => [['P_CD_DATO' => '9', 'P_DE_DATO' => 'CBR']],
            ],
            'versions' => [
                '1|40|2' => [
                    ['P_CD_DATO' => '1', 'P_DE_DATO' => 'LX'],
                    ['P_CD_DATO' => '2', 'P_DE_DATO' => 'EX'],
                    ['P_CD_DATO' => '3', 'P_DE_DATO' => 'EX-L'],
                ],
                '1|2|5' => [['P_CD_DATO' => '7', 'P_DE_DATO' => 'LE']],
                '3|41|8' => [['P_CD_DATO' => '4', 'P_DE_DATO' => 'LX']],
            ],
        ], $overrides);
    }

    private function service(array $catalog): HumanoService
    {
        $service = Mockery::mock(HumanoService::class);
        $service->shouldReceive('getMarcas')->andReturnUsing(function (string $plan) use ($catalog): array {
            $this->calls[] = 'marcas|' . $plan;

            return ['payload' => $catalog['brands'][$plan] ?? []];
        });
        $service->shouldReceive('getModelos')->andReturnUsing(function (string $plan, string $brand) use ($catalog): array {
            $this->calls[] = 'modelos|' . $plan . '|' . $brand;

            return ['payload' => $catalog['models'][$plan . '|' . $brand] ?? []];
        });
        $service->shouldReceive('getVersiones')->andReturnUsing(
            function (string $plan, string $brand, string $model) use ($catalog): array {
                $this->calls[] = 'versiones|' . $plan . '|' . $brand . '|' . $model;

                return ['payload' => $catalog['versions'][$plan . '|' . $brand . '|' . $model] ?? []];
            }
        );

        return $service;
    }

    private function provider(?array $catalog = null): HumanoProvider
    {
        return new HumanoProvider(
            app: app(Apps::class),
            company: auth()->user()->getCurrentCompany(),
            service: $this->service($catalog ?? $this->rows()),
        );
    }

    private function insurer(string $provider = HumanoProvider::NAME): Insurer
    {
        return new CreateInsurerAction(
            InsurerData::from(
                app(Apps::class),
                auth()->user()->getCurrentCompany(),
                ['name' => 'Humano ' . uniqid(), 'provider' => $provider]
            )
        )->execute();
    }

    private function vehicles(): array
    {
        return [
            new CanonicalVehicle('Honda', 'Civic'),
            new CanonicalVehicle('TOYOTA', 'Corolla'),
            new CanonicalVehicle('Toyota', 'Hilux'),
        ];
    }

    private function build(Insurer $insurer, ?array $catalog = null, ?array $vehicles = null): int
    {
        return new BuildVehicleModelCrosswalkAction(
            $insurer,
            $this->provider($catalog),
            $vehicles ?? $this->vehicles(),
        )->execute();
    }

    private function walkCalls(): int
    {
        return count(array_filter($this->calls, fn (string $call): bool => ! str_starts_with($call, 'marcas|')));
    }

    private function mapRows(Insurer $insurer)
    {
        return InsuranceVehicleModelMap::query()->where('insurers_id', $insurer->getId());
    }

    private function resolve(string $plan, CanonicalVehicle $vehicle): ?ExternalVehicleReference
    {
        return new InsuranceVehicleModelResolver()->resolveReference(
            app(Apps::class),
            HumanoProvider::NAME,
            $plan,
            $vehicle
        );
    }

    public function testBuildWritesModelAndVersionRowsPerPlanAndSkipsUnmatchedVehicles(): void
    {
        $insurer = $this->insurer();

        $matched = $this->build($insurer);

        $this->assertSame(3, $matched);
        $full = $this->mapRows($insurer)->where('plan_code', 'mi_auto_full')->get();
        $this->assertCount(6, $full);
        $this->assertEmpty($full->where('model_key', 'hilux'));
        $ex = $full->where('model_key', 'civic')->firstWhere('version_key', 'ex');
        $this->assertSame('40', $ex->external_brand_id);
        $this->assertSame('2', $ex->external_model_id);
        $this->assertSame('2', $ex->external_version_id);
        $this->assertSame('builder', $ex->source);
        $modelRow = $full->where('model_key', 'civic')->firstWhere('version_key', '');
        $this->assertNull($modelRow->external_version_id);
        $this->assertSame(2, $this->mapRows($insurer)->where('plan_code', 'mi_auto_flex')->count());
        $this->assertSame(0, $this->mapRows($insurer)->where('plan_code', 'mi_moto_basico')->count());
    }

    public function testCodesAreKeptPerPlanBecauseHumanoCataloguesDifferPerPlan(): void
    {
        $this->build($this->insurer());
        $civicLx = new CanonicalVehicle('Honda', 'Civic', null, 'LX');

        $full = $this->resolve('mi_auto_full', $civicLx);
        $flex = $this->resolve('mi_auto_flex', $civicLx);

        $this->assertSame('40', $full->brandId);
        $this->assertSame('2', $full->modelId);
        $this->assertSame('1', $full->versionId);
        $this->assertSame('41', $flex->brandId);
        $this->assertSame('8', $flex->modelId);
        $this->assertSame('4', $flex->versionId);
    }

    public function testAPlanWithoutThatVehicleInItsCatalogResolvesToNothing(): void
    {
        $this->build($this->insurer());

        $this->assertNull($this->resolve('mi_auto_basico', new CanonicalVehicle('Honda', 'Civic', null, 'LX')));
        $this->assertNull($this->resolve('mi_moto_basico', new CanonicalVehicle('Honda', 'Civic', null, 'LX')));
    }

    public function testBuildIsIdempotentAndCachedCatalogsAreNotRefetched(): void
    {
        $insurer = $this->insurer();

        $this->build($insurer);
        $first = $this->mapRows($insurer)->orderBy('id')->pluck('external_version_id', 'id')->all();
        $walksAfterFirst = $this->walkCalls();
        $this->build($insurer);
        $second = $this->mapRows($insurer)->orderBy('id')->pluck('external_version_id', 'id')->all();

        $this->assertSame($first, $second);
        $this->assertSame($walksAfterFirst, $this->walkCalls());
    }

    public function testResolverMatchesTheVersionByNormalizedNameAndHonoursMissingOnes(): void
    {
        $this->build($this->insurer());

        $hit = $this->resolve('mi_auto_full', new CanonicalVehicle('HONDA', 'civic', 2021, 'ex l'));
        $noVersion = $this->resolve('mi_auto_full', new CanonicalVehicle('Honda', 'Civic'));
        $unknownVersion = $this->resolve('mi_auto_full', new CanonicalVehicle('Honda', 'Civic', null, 'Type R'));
        $unmappedModel = $this->resolve('mi_auto_full', new CanonicalVehicle('Toyota', 'Hilux', null, 'LE'));

        $this->assertSame('3', $hit->versionId);
        $this->assertNull($noVersion->versionId);
        $this->assertNull($unknownVersion);
        $this->assertNull($unmappedModel);
    }

    public function testUniversalLookupIgnoresHumanosPlanScopedRows(): void
    {
        $this->build($this->insurer());

        $this->assertNull(
            new InsuranceVehicleModelResolver()->resolve(app(Apps::class), HumanoProvider::NAME, new CanonicalVehicle('Honda', 'Civic'))
        );
    }

    public function testModelsAreOnlyUniqueWithinTheirBrand(): void
    {
        $this->build($this->insurer());

        $honda = $this->resolve('mi_auto_full', new CanonicalVehicle('Honda', 'Civic', null, 'EX'));

        $this->assertSame('2', $honda->modelId);
        $this->assertNull($this->resolve('mi_auto_full', new CanonicalVehicle('Acura', 'Civic', null, 'EX')));
    }

    public function testManualRowsAreNeverOverwrittenOrDeactivated(): void
    {
        $insurer = $this->insurer();
        InsuranceVehicleModelMap::query()->create([
            'apps_id' => $insurer->apps_id,
            'insurers_id' => $insurer->getId(),
            'plan_code' => 'mi_auto_full',
            'brand_key' => 'honda',
            'model_key' => 'civic',
            'version_key' => 'ex',
            'year' => 0,
            'external_brand_id' => '40',
            'external_model_id' => '2',
            'external_version_id' => '999',
            'source' => 'manual',
            'active' => true,
        ]);

        $this->build($insurer);
        $this->build($insurer, $this->rows(['models' => ['1|40' => [['P_CD_DATO' => '1', 'P_DE_DATO' => 'ACCORD']]]]));

        $manual = $this->mapRows($insurer)->where('source', 'manual')->first();
        $this->assertSame('999', $manual->external_version_id);
        $this->assertTrue((bool) $manual->active);
    }

    public function testAnEmptyBrandCatalogNeverDeactivatesRows(): void
    {
        $insurer = $this->insurer();
        $this->build($insurer);

        Cache::flush();
        $this->build($insurer, ['brands' => [], 'models' => [], 'versions' => []]);

        $this->assertSame(0, $this->mapRows($insurer)->where('active', false)->count());
    }

    public function testAModelTheCatalogDroppedIsDeactivatedAndAVersionWithItButNotOthers(): void
    {
        $insurer = $this->insurer();
        $this->build($insurer);

        Cache::flush();
        $this->build($insurer, [
            'brands' => ['1' => [['P_CD_DATO' => '40', 'P_DE_DATO' => 'HONDA'], ['P_CD_DATO' => '2', 'P_DE_DATO' => 'TOYOTA']]],
            'models' => [
                '1|40' => [['P_CD_DATO' => '2', 'P_DE_DATO' => 'CIVIC']],
                '1|2' => [['P_CD_DATO' => '6', 'P_DE_DATO' => 'YARIS']],
            ],
            'versions' => ['1|40|2' => [['P_CD_DATO' => '1', 'P_DE_DATO' => 'LX']]],
        ]);

        $active = $this->mapRows($insurer)->where('plan_code', 'mi_auto_full')->get()
            ->mapWithKeys(fn ($row) => [$row->model_key . '|' . $row->version_key => (bool) $row->active]);

        $this->assertFalse($active['corolla|']);
        $this->assertFalse($active['corolla|le']);
        $this->assertTrue($active['civic|']);
        $this->assertTrue($active['civic|lx']);
        $this->assertFalse($active['civic|ex']);
        $this->assertFalse($active['civic|exl']);
    }

    public function testAnEmptyVersionListNeverDeactivatesTheVersionsAlreadyKnown(): void
    {
        $insurer = $this->insurer();
        $this->build($insurer);

        Cache::flush();
        $catalog = $this->rows();
        $catalog['versions']['1|40|2'] = [];
        $this->build($insurer, $catalog);

        $this->assertSame(0, $this->mapRows($insurer)->where('plan_code', 'mi_auto_full')->where('model_key', 'civic')->where('active', false)->count());
    }

    public function testAPlanWhoseBrandModelsComeBackEmptyIsNotTreatedAsScanned(): void
    {
        $insurer = $this->insurer();
        $this->build($insurer);

        Cache::flush();
        $catalog = $this->rows();
        $catalog['models']['1|40'] = [];
        $this->build($insurer, $catalog);

        $this->assertSame(0, $this->mapRows($insurer)->where('plan_code', 'mi_auto_full')->where('active', false)->count());
    }

    public function testAFailingPlanIsSkippedWithoutLosingTheOthersOrDeactivatingIt(): void
    {
        $insurer = $this->insurer();
        $this->build($insurer);

        Cache::flush();
        $service = Mockery::mock(HumanoService::class);
        $service->shouldReceive('getMarcas')->andReturnUsing(function (string $plan): array {
            if ($plan === '1') {
                throw new RuntimeException('upstream 500');
            }

            return ['payload' => $plan === '3' ? [['P_CD_DATO' => '41', 'P_DE_DATO' => 'HONDA']] : []];
        });
        $service->shouldReceive('getModelos')->andReturn(['payload' => [['P_CD_DATO' => '8', 'P_DE_DATO' => 'CIVIC']]]);
        $service->shouldReceive('getVersiones')->andReturn(['payload' => [['P_CD_DATO' => '4', 'P_DE_DATO' => 'LX']]]);
        $provider = new HumanoProvider(
            app: app(Apps::class),
            company: auth()->user()->getCurrentCompany(),
            service: $service,
        );

        new BuildVehicleModelCrosswalkAction($insurer, $provider, $this->vehicles())->execute();

        $this->assertSame(0, $this->mapRows($insurer)->where('plan_code', 'mi_auto_full')->where('active', false)->count());
        $this->assertSame('4', $this->resolve('mi_auto_flex', new CanonicalVehicle('Honda', 'Civic', null, 'LX'))?->versionId);
    }

    public function testOnlyTheTargetedBrandsModelsAndVersionsAreFetched(): void
    {
        $this->build($this->insurer(), null, [new CanonicalVehicle('Honda', 'Civic')]);

        $this->assertNotContains('modelos|1|1', $this->calls);
        $this->assertNotContains('modelos|1|2', $this->calls);
        $this->assertContains('modelos|1|40', $this->calls);
        $this->assertContains('versiones|1|40|2', $this->calls);
        $this->assertNotContains('versiones|1|40|1', $this->calls);
    }

    public function testTheCrosswalkJobBuildsHumanoThroughTheTargetedContract(): void
    {
        $insurer = $this->insurer();
        $provider = $this->provider();
        app()->bind('insurance_provider.' . HumanoProvider::NAME, fn () => $provider);

        new BuildVehicleModelCrosswalkJob(app(Apps::class), auth()->user()->getCurrentCompany())->handle();

        $this->assertContains('marcas|1', $this->calls);
        $this->assertNotNull($insurer->getId());
    }

    public function testRepeatedVehiclesOfTheSameModelAreWalkedOnce(): void
    {
        $this->build($this->insurer(), null, [
            new CanonicalVehicle('Honda', 'Civic', 2020),
            new CanonicalVehicle('HONDA', 'civic', 2022),
        ]);

        $this->assertSame(1, count(array_keys($this->calls, 'versiones|1|40|2', true)));
    }
}
