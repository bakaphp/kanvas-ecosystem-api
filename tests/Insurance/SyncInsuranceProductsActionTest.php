<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Baka\Support\Str;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\UniversalSeguros\Enums\ConfigurationEnum;
use Kanvas\Connectors\UniversalSeguros\Enums\ProductEnum;
use Kanvas\Connectors\UniversalSeguros\Providers\UniversalSegurosProvider;
use Kanvas\Connectors\UniversalSeguros\Services\UniversalSegurosService;
use Kanvas\Insurance\Actions\SyncInsuranceProductsAction;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Inventory\Products\Models\Products;
use Mockery;
use Tests\TestCase;

class SyncInsuranceProductsActionTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Products go to the `inventory` connection, which the default [null] leaves
     * committed — the seed would survive into the next test and be skipped.
     */
    protected $connectionsToTransact = [null, 'inventory', 'ecosystem'];

    /** What Universal actually granted in QA: A-PA, A-KM and A-PL, but not A-PC or A-PT. */
    private const PARTIAL_GRANT = 'unit.serviceplattform.externos unit.serviceplattform.cotizaciones '
        . 'unit.serviceplattform.polizas unit.serviceplattform.emitir.paratusegurodeley '
        . 'unit.serviceplattform.emitir.paratuauto unit.serviceplattform.emitir.porloqueconduces';

    private function action(Apps $app, Companies $company): SyncInsuranceProductsAction
    {
        return new SyncInsuranceProductsAction(
            new UniversalSegurosProvider(
                app: $app,
                company: $company,
                // products() is a fixed table on their side, so nothing is fetched.
                service: Mockery::mock(UniversalSegurosService::class),
            ),
            $app,
            $company,
        );
    }

    public function testSeedsOneCatalogProductPerPolicyLine(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $created = $this->action($app, $company)->execute();

        $this->assertCount(count(ProductEnum::cases()), $created);
        $this->assertSame(
            ['universal_seguros-a-pa', 'universal_seguros-a-km', 'universal_seguros-a-pc', 'universal_seguros-a-pt', 'universal_seguros-a-pl'],
            array_map(fn (Products $product): string => $product->slug, $created)
        );
    }

    public function testTheInsurersProductCodeSurvivesOnTheProduct(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $created = $this->action($app, $company)->execute();
        $paraTuAuto = $created[0];

        // Attributes.name is translatable, so it is stored as JSON and cannot be
        // matched directly — the slug is what addAttributes() derives and indexes on.
        $code = $paraTuAuto->getAttributeBySlug(Str::slug(InsuranceCustomFieldEnum::PRODUCT_CODE->value));
        $inspection = $paraTuAuto->getAttributeBySlug(Str::slug(InsuranceCustomFieldEnum::REQUIRES_INSPECTION->value));

        $this->assertNotNull($code);
        $this->assertSame(ProductEnum::PARA_TU_AUTO->value, $code->value);
        // Stored as '1'/'0'; the attribute cast hands it back as an int.
        $this->assertEquals(1, $inspection?->value);
    }

    public function testLinesTheAliadoCannotEmitAreSeededUnpublished(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $company->set(ConfigurationEnum::SCOPES->value, self::PARTIAL_GRANT);

        $published = [];
        foreach ($this->action($app, $company)->execute() as $product) {
            $published[$product->slug] = (bool) $product->is_published;
        }

        $this->assertTrue($published['universal_seguros-a-pa']);
        $this->assertTrue($published['universal_seguros-a-pl']);
        $this->assertFalse($published['universal_seguros-a-pc']);
        $this->assertFalse($published['universal_seguros-a-pt']);
    }

    /**
     * Why this is publish state and not a skipped row: the line lights up keeping
     * its id, copy and history.
     */
    public function testAGrantArrivingLaterPublishesTheExistingRow(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $company->set(ConfigurationEnum::SCOPES->value, self::PARTIAL_GRANT);
        $created = $this->action($app, $company)->execute();

        $porSiChocas = collect($created)->firstWhere('slug', 'universal_seguros-a-pc');
        $this->assertFalse((bool) $porSiChocas->is_published);

        $company->set(
            ConfigurationEnum::SCOPES->value,
            self::PARTIAL_GRANT . ' ' . ProductEnum::POR_SI_CHOCAS->emitScope()
        );
        $this->action($app, $company)->execute();

        $this->assertTrue((bool) $porSiChocas->fresh()->is_published);
    }

    public function testRerunningLeavesAdminEditedCopyUntouched(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $created = $this->action($app, $company)->execute();
        $edited = $created[0];
        $edited->name = 'Seguro Full Cobertura — MoviPass';
        $edited->description = 'Copy escrito por el equipo comercial';
        $edited->saveQuietly();

        $secondRun = $this->action($app, $company)->execute();

        $this->assertSame([], $secondRun);
        $this->assertSame('Seguro Full Cobertura — MoviPass', $edited->fresh()->name);
        $this->assertSame(
            count(ProductEnum::cases()),
            Products::where([
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
            ])->where('slug', 'like', 'universal_seguros-%')->count()
        );
    }
}
