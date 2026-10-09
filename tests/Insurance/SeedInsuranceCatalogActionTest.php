<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Humano\Enums\PlanEnum;
use Kanvas\Connectors\UniversalSeguros\Enums\ProductEnum;
use Kanvas\Insurance\Actions\SeedInsuranceCatalogAction;
use Kanvas\Insurance\Enums\InsuranceCatalogStatusEnum;
use Kanvas\Insurance\Enums\InsuranceCoverageCategoryEnum;
use Kanvas\Insurance\Models\Insurer;
use Tests\TestCase;

class SeedInsuranceCatalogActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'commerce'];

    private function seedCatalog(): array
    {
        return new SeedInsuranceCatalogAction(app(Apps::class), auth()->user()->getCurrentCompany())->execute();
    }

    private function insurer(string $slug): Insurer
    {
        return Insurer::query()
            ->fromApp(app(Apps::class))
            ->fromCompany(auth()->user()->getCurrentCompany())
            ->where('slug', $slug)
            ->firstOrFail();
    }

    public function testSeedsBothInsurersWithOnePlanPerAdapterProduct(): void
    {
        $created = $this->seedCatalog();

        $this->assertCount(count(ProductEnum::cases()) + count(PlanEnum::cases()), $created);
        $this->assertCount(count(ProductEnum::cases()), $this->insurer('universal-seguros')->plans);
        $this->assertCount(count(PlanEnum::cases()), $this->insurer('humano')->plans);
    }

    public function testSeededPlansCarryTheCanonicalCategoryAndExternalCode(): void
    {
        $this->seedCatalog();
        $universal = $this->insurer('universal-seguros');
        $plans = $universal->plans->keyBy('external_code');

        $this->assertSame('universal_seguros', $universal->provider);
        $this->assertSame(InsuranceCoverageCategoryEnum::LAW, $plans['A-PL']->type);
        $this->assertSame(InsuranceCoverageCategoryEnum::FULL, $plans['A-PA']->type);

        $humano = $this->insurer('humano')->plans->keyBy('external_code');
        $this->assertSame(InsuranceCoverageCategoryEnum::LAW, $humano['mi_auto_basico']->type);
        $this->assertSame(InsuranceCoverageCategoryEnum::FULL, $humano['mi_auto_full']->type);
    }

    public function testSecondRunCreatesNothing(): void
    {
        $this->seedCatalog();

        $this->assertSame([], $this->seedCatalog());
        $this->assertCount(count(ProductEnum::cases()), $this->insurer('universal-seguros')->plans);
    }

    public function testRunNeverRewritesAdminEditedRows(): void
    {
        $this->seedCatalog();
        $plan = $this->insurer('universal-seguros')->plans->firstWhere('external_code', 'A-PL');
        $plan->name = 'Edited by admin';
        $plan->base_price = 999;
        $plan->sponsored = true;
        $plan->status = InsuranceCatalogStatusEnum::INACTIVE;
        $plan->type = InsuranceCoverageCategoryEnum::FULL;
        $plan->save();

        $insurer = $this->insurer('universal-seguros');
        $insurer->name = 'Renamed insurer';
        $insurer->save();

        $this->seedCatalog();

        $plan->refresh();
        $this->assertSame('Edited by admin', $plan->name);
        $this->assertSame('999.00', $plan->base_price);
        $this->assertTrue($plan->sponsored);
        $this->assertSame(InsuranceCatalogStatusEnum::INACTIVE, $plan->status);
        $this->assertSame(InsuranceCoverageCategoryEnum::FULL, $plan->type);
        $this->assertSame('Renamed insurer', $this->insurer('universal-seguros')->name);
    }

    public function testDoesNotResurrectOrDuplicateASoftDeletedPlan(): void
    {
        $this->seedCatalog();
        $this->insurer('universal-seguros')->plans->firstWhere('external_code', 'A-PT')->delete();

        $this->assertSame([], $this->seedCatalog());
        $this->assertCount(count(ProductEnum::cases()) - 1, $this->insurer('universal-seguros')->plans);
    }
}
